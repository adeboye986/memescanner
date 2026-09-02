<?php

namespace Tests\Feature;

use App\Chain;
use App\Models\PaperPosition;
use App\Models\PaperWallet;
use App\Services\DatabaseLockRetryService;
use App\Services\TelegramService;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use PDOException;
use Tests\Concerns\RefreshesPaperTradingDatabase;
use Tests\TestCase;

class PaperTrackerStrategyTest extends TestCase
{
    use RefreshesPaperTradingDatabase;

    /** @var list<string> */
    private array $telegramMessages = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshPaperTradingDatabase();
        $this->mock(TelegramService::class)
            ->shouldReceive('send')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function (string $message): void {
                $this->telegramMessages[] = $message;
            });
    }

    public function test_stop_loss_uses_actual_observed_fill_and_updates_only_its_wallet(): void
    {
        $wallet = $this->createWallet(Chain::Solana);
        $position = $this->createPosition(Chain::Solana);

        $this->trackAt($position, 0.85);

        $event = $position->fresh()->exit_events[0];
        $this->assertSame('closed', $position->fresh()->status);
        $this->assertSame('stop_loss', $event['type']);
        $this->assertEqualsWithDelta(0.90, $event['trigger_multiple'], 0.000001);
        $this->assertEqualsWithDelta(0.85, $event['fill_multiple'], 0.000001);
        $this->assertEqualsWithDelta(0.085, $event['sol_returned'], 0.000001);
        $this->assertEqualsWithDelta(-0.015, $position->fresh()->trade_pnl_sol, 0.000001);
        $this->assertEqualsWithDelta(4.985, $wallet->fresh()->available_balance_sol, 0.000001);
        $this->assertEqualsWithDelta(0, $wallet->fresh()->invested_balance_sol, 0.000001);
    }

    public function test_two_x_arms_without_selling_then_later_fallback_exits_at_observed_fill(): void
    {
        $wallet = $this->createWallet(Chain::Solana);
        $position = $this->createPosition(Chain::Solana);

        $this->trackAt($position, 2.10);

        $this->assertSame('open', $position->fresh()->status);
        $this->assertTrue($position->fresh()->tp_50_hit);
        $this->assertSame([], $position->fresh()->exit_events);
        $this->assertEqualsWithDelta(4.9, $wallet->fresh()->available_balance_sol, 0.000001);

        $this->trackAt($position, 1.95);

        $event = $position->fresh()->exit_events[0];
        $this->assertSame('closed', $position->fresh()->status);
        $this->assertSame(100, $event['protected_floor_profit_percent']);
        $this->assertEqualsWithDelta(2.0, $event['trigger_multiple'], 0.000001);
        $this->assertEqualsWithDelta(1.95, $event['fill_multiple'], 0.000001);
        $this->assertEqualsWithDelta(5.095, $wallet->fresh()->available_balance_sol, 0.000001);
    }

    public function test_three_x_upgrades_without_selling_then_later_fallback_exits_at_observed_fill(): void
    {
        $wallet = $this->createWallet(Chain::Solana);
        $position = $this->createPosition(Chain::Solana);

        $this->trackAt($position, 3.10);
        $this->assertSame('open', $position->fresh()->status);
        $this->assertTrue($position->fresh()->tp_50_hit);
        $this->assertTrue($position->fresh()->tp_2x_hit);
        $this->assertSame([], $position->fresh()->exit_events);

        $this->trackAt($position, 2.90);

        $event = $position->fresh()->exit_events[0];
        $this->assertSame('closed', $position->fresh()->status);
        $this->assertSame(200, $event['protected_floor_profit_percent']);
        $this->assertEqualsWithDelta(3.0, $event['trigger_multiple'], 0.000001);
        $this->assertEqualsWithDelta(2.90, $event['fill_multiple'], 0.000001);
        $this->assertEqualsWithDelta(5.19, $wallet->fresh()->available_balance_sol, 0.000001);
    }

    public function test_position_stays_open_while_above_its_active_floor(): void
    {
        $this->createWallet(Chain::Solana);
        $position = $this->createPosition(Chain::Solana);

        $this->trackAt($position, 2.10);
        $this->trackAt($position, 2.40);
        $this->trackAt($position, 3.10);
        $this->trackAt($position, 3.05);

        $this->assertSame('open', $position->fresh()->status);
        $this->assertSame([], $position->fresh()->exit_events);
    }

    public function test_each_protection_notification_is_sent_only_when_its_floor_first_arms(): void
    {
        $this->createWallet(Chain::Solana);
        $position = $this->createPosition(Chain::Solana);

        $this->trackAt($position, 2.10);
        $this->trackAt($position, 2.40);
        $this->trackAt($position, 3.10);
        $this->trackAt($position, 3.20);

        $atOneHundred = array_filter(
            $this->telegramMessages,
            fn (string $message): bool => str_contains($message, '+100% PROFIT PROTECTED'),
        );
        $atTwoHundred = array_filter(
            $this->telegramMessages,
            fn (string $message): bool => str_contains($message, '+200% PROFIT PROTECTED'),
        );

        $this->assertCount(1, $atOneHundred);
        $this->assertCount(1, $atTwoHundred);
    }

    public function test_closed_position_is_idempotent_on_later_tracker_runs(): void
    {
        $wallet = $this->createWallet(Chain::Solana);
        $position = $this->createPosition(Chain::Solana);

        $this->trackAt($position, 0.85);
        $balanceAfterClose = (float) $wallet->fresh()->available_balance_sol;
        $eventsAfterClose = $position->fresh()->exit_events;

        $this->trackAt($position, 0.50);

        $this->assertEqualsWithDelta($balanceAfterClose, $wallet->fresh()->available_balance_sol, 0.000001);
        $this->assertSame($eventsAfterClose, $position->fresh()->exit_events);
    }

    public function test_solana_and_ethereum_exits_are_accounted_in_separate_wallets(): void
    {
        $solanaWallet = $this->createWallet(Chain::Solana);
        $ethereumWallet = $this->createWallet(Chain::Ethereum);
        $solanaPosition = $this->createPosition(Chain::Solana, 'sol-token');
        $ethereumPosition = $this->createPosition(Chain::Ethereum, '0xeth-token');

        $this->trackAt($solanaPosition, 0.80);
        $this->assertEqualsWithDelta(4.98, $solanaWallet->fresh()->available_balance_sol, 0.000001);
        $this->assertEqualsWithDelta(4.90, $ethereumWallet->fresh()->available_balance_sol, 0.000001);

        $this->trackAt($ethereumPosition, 2.10);
        $this->trackAt($ethereumPosition, 1.90);

        $this->assertEqualsWithDelta(4.98, $solanaWallet->fresh()->available_balance_sol, 0.000001);
        $this->assertEqualsWithDelta(5.09, $ethereumWallet->fresh()->available_balance_sol, 0.000001);
        $this->assertEqualsWithDelta(-0.02, $solanaWallet->fresh()->realized_pnl_sol, 0.000001);
        $this->assertEqualsWithDelta(0.09, $ethereumWallet->fresh()->realized_pnl_sol, 0.000001);
    }

    public function test_provider_errors_and_missing_prices_never_close_or_credit_a_position(): void
    {
        $wallet = $this->createWallet(Chain::Solana);
        $position = $this->createPosition(Chain::Solana);

        foreach ([Http::failedConnection('timeout'), Http::response([], 429), Http::response([], 500), Http::response([], 200)] as $response) {
            $http = new Factory;
            Http::swap($http);
            Http::fake(['api.dexscreener.com/tokens/v1/solana/*' => $response]);

            $this->artisan('tokens:paper-track')->assertSuccessful();

            $this->assertSame('open', $position->fresh()->status);
            $this->assertNull($position->fresh()->closed_at);
            $this->assertSame([], $position->fresh()->exit_events);
            $this->assertEqualsWithDelta(4.9, $wallet->fresh()->available_balance_sol, 0.000001);
        }
    }

    public function test_periodic_snapshots_are_throttled_but_exit_snapshot_is_immediate(): void
    {
        config()->set('services.trading.paper_tracker_snapshot_seconds', 10);
        $this->createWallet(Chain::Solana);
        $position = $this->createPosition(Chain::Solana);

        $this->trackAt($position, 1.01);
        $this->trackAt($position, 1.02);
        $this->trackAt($position, 0.85);

        $this->assertSame(1, $position->snapshots()->where('snapshot_type', 'periodic')->count());
        $this->assertSame(1, $position->snapshots()->where('snapshot_type', 'exit')->count());
    }

    public function test_fast_tracker_refuses_to_start_when_process_lock_is_owned(): void
    {
        $lock = Cache::lock('paper-tracker.fast.process', 30);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('tokens:paper-track:fast', ['--max-cycles' => 1])
                ->expectsOutputToContain('already owns the process lock')
                ->assertFailed();
        } finally {
            $lock->release();
        }
    }

    public function test_positions_on_the_same_chain_are_priced_in_one_batch_request(): void
    {
        $this->createWallet(Chain::Solana);
        $first = $this->createPosition(Chain::Solana, 'first-token');
        $second = $this->createPosition(Chain::Solana, 'second-token');
        $http = new Factory;
        Http::swap($http);
        Http::fake([
            'api.dexscreener.com/tokens/v1/solana/*' => Http::response([
                $this->pairFor($first, 1.01),
                $this->pairFor($second, 1.02),
            ]),
        ]);

        $this->artisan('tokens:paper-track')->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertNotNull($first->fresh()->last_checked_at);
        $this->assertNotNull($second->fresh()->last_checked_at);
    }

    public function test_fast_tracker_records_measured_cycle_health(): void
    {
        $this->artisan('tokens:paper-track:fast', ['--max-cycles' => 1])
            ->expectsOutputToContain('Fast paper tracker started')
            ->assertSuccessful();

        $health = Cache::get('paper-tracker.fast.health');
        $this->assertIsArray($health);
        $this->assertSame(0, $health['open_positions']);
        $this->assertSame(0, $health['priced_positions']);
        $this->assertSame(0, $health['provider_requests']);
        $this->assertIsFloat($health['cycle_duration_ms']);
    }

    public function test_fast_tracker_survives_an_exhausted_lock_and_continues_the_next_cycle(): void
    {
        $this->createWallet(Chain::Solana);
        $position = $this->createPosition(Chain::Solana);
        $calls = 0;

        $this->partialMock(DatabaseLockRetryService::class, function (MockInterface $mock) use (&$calls): void {
            $mock->shouldReceive('run')->andReturnUsing(function (callable $operation) use (&$calls): mixed {
                $calls++;

                if ($calls === 1) {
                    throw new PDOException('SQLSTATE[HY000]: General error: 5 database is locked');
                }

                return $operation();
            });
        });
        $http = new Factory;
        Http::swap($http);
        Http::fake([
            'api.dexscreener.com/tokens/v1/solana/*' => Http::response([$this->pairFor($position, 1.01)]),
        ]);

        $this->artisan('tokens:paper-track:fast', ['--max-cycles' => 2])
            ->expectsOutputToContain('PAPER TRACK DB LOCK: cycle skipped after retries')
            ->expectsOutputToContain('Fast paper tracker stopped after 2 cycle(s).')
            ->assertSuccessful();

        $this->assertNotNull($position->fresh()->last_checked_at);
    }

    public function test_scheduled_tracker_treats_an_exhausted_sqlite_lock_as_a_recoverable_cycle(): void
    {
        $this->partialMock(DatabaseLockRetryService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->once()->andThrow(
                new PDOException('SQLSTATE[HY000]: General error: 5 database is locked'),
            );
        });

        $this->artisan('tokens:paper-track')
            ->expectsOutputToContain('PAPER TRACK DB LOCK: cycle skipped after retries')
            ->assertSuccessful();
    }

    public function test_lock_reported_after_an_exit_commit_cannot_duplicate_the_exit_or_wallet_credit(): void
    {
        $wallet = $this->createWallet(Chain::Solana);
        $position = $this->createPosition(Chain::Solana);
        $calls = 0;

        $this->partialMock(DatabaseLockRetryService::class, function (MockInterface $mock) use (&$calls): void {
            $mock->shouldReceive('run')->andReturnUsing(function (callable $operation) use (&$calls): mixed {
                $calls++;
                $result = $operation();

                if ($calls === 2) {
                    throw new PDOException('SQLSTATE[HY000]: General error: 5 database is locked');
                }

                return $result;
            });
        });
        $http = new Factory;
        Http::swap($http);
        Http::fake([
            'api.dexscreener.com/tokens/v1/solana/*' => Http::response([$this->pairFor($position, 0.85)]),
        ]);

        $this->artisan('tokens:paper-track:fast', ['--max-cycles' => 2])->assertSuccessful();

        $fresh = $position->fresh();
        $this->assertSame('closed', $fresh->status);
        $this->assertCount(1, $fresh->exit_events);
        $this->assertEqualsWithDelta(4.985, $wallet->fresh()->available_balance_sol, 0.000001);
        $this->assertEqualsWithDelta(0, $wallet->fresh()->invested_balance_sol, 0.000001);
        $this->assertLessThanOrEqual(1, count(array_filter(
            $this->telegramMessages,
            fn (string $message): bool => str_contains($message, 'PAPER STOP LOSS'),
        )));
    }

    public function test_unchanged_high_frequency_observation_does_not_rewrite_the_position_every_second(): void
    {
        config()->set('services.trading.paper_tracker_persist_seconds', 5);
        $this->createWallet(Chain::Solana);
        $position = $this->createPosition(Chain::Solana);
        $checkedAt = now();
        $position->forceFill(['last_checked_at' => $checkedAt])->save();
        $baseline = $position->fresh();
        $checkedAt = $baseline->last_checked_at;
        $updatedAt = $baseline->updated_at;

        $this->trackAt($baseline, 1.0);

        $fresh = $position->fresh();
        $this->assertTrue($fresh->last_checked_at->equalTo($checkedAt));
        $this->assertTrue($fresh->updated_at->equalTo($updatedAt));
    }

    public function test_custom_strategy_thresholds_arm_hold_upgrade_and_exit_on_a_later_observed_fill(): void
    {
        $wallet = $this->createWallet(Chain::Solana);
        $position = $this->createPosition(Chain::Solana, strategy: [
            'stop_loss_percent' => 20,
            'protection_level_1_percent' => 50,
            'protection_level_2_percent' => 120,
        ]);

        $this->trackAt($position, 1.60);
        $this->assertTrue($position->fresh()->tp_50_hit);
        $this->assertFalse($position->fresh()->tp_2x_hit);
        $this->assertSame('open', $position->fresh()->status);
        $this->assertSame([], $position->fresh()->exit_events);

        $this->trackAt($position, 2.30);
        $this->assertTrue($position->fresh()->tp_2x_hit);
        $this->assertSame('open', $position->fresh()->status);
        $this->assertSame([], $position->fresh()->exit_events);

        $this->trackAt($position, 2.10);
        $event = $position->fresh()->exit_events[0];
        $this->assertSame('closed', $position->fresh()->status);
        $this->assertEqualsWithDelta(2.20, $event['trigger_multiple'], 0.000001);
        $this->assertEqualsWithDelta(2.10, $event['fill_multiple'], 0.000001);
        $this->assertEqualsWithDelta(5.11, $wallet->fresh()->available_balance_sol, 0.000001);
    }

    public function test_custom_stop_loss_is_shared_by_scheduled_and_fast_trackers(): void
    {
        $this->createWallet(Chain::Solana);
        $strategy = [
            'stop_loss_percent' => 20,
            'protection_level_1_percent' => 100,
            'protection_level_2_percent' => 200,
        ];
        $scheduled = $this->createPosition(Chain::Solana, 'scheduled-token', $strategy);

        $this->trackAt($scheduled, 0.75);
        $scheduledEvent = $scheduled->fresh()->exit_events[0];

        $fast = $this->createPosition(Chain::Solana, 'fast-token', $strategy);
        $http = new Factory;
        Http::swap($http);
        Http::fake([
            'api.dexscreener.com/tokens/v1/solana/*' => Http::response([$this->pairFor($fast, 0.75)]),
        ]);
        $this->artisan('tokens:paper-track:fast', ['--max-cycles' => 1])->assertSuccessful();
        $fastEvent = $fast->fresh()->exit_events[0];

        $this->assertEqualsWithDelta(0.80, $scheduledEvent['trigger_multiple'], 0.000001);
        $this->assertEqualsWithDelta($scheduledEvent['trigger_multiple'], $fastEvent['trigger_multiple'], 0.000001);
        $this->assertEqualsWithDelta($scheduledEvent['fill_multiple'], $fastEvent['fill_multiple'], 0.000001);
    }

    private function createWallet(Chain $chain): PaperWallet
    {
        return PaperWallet::query()->create([
            'name' => 'default',
            'chain' => $chain->value,
            'currency' => $chain === Chain::Solana ? 'SOL' : 'ETH',
            'starting_balance_sol' => 5,
            'available_balance_sol' => 4.9,
            'invested_balance_sol' => 0.1,
            'realized_pnl_sol' => 0,
        ]);
    }

    /** @param array<string, float|int>|null $strategy */
    private function createPosition(Chain $chain, ?string $address = null, ?array $strategy = null): PaperPosition
    {
        return PaperPosition::query()->create([
            'chain' => $chain->value,
            'address' => $address ?? $chain->value.'-token',
            'symbol' => strtoupper(substr($chain->value, 0, 3)),
            'entry_market_cap' => 100_000,
            'last_market_cap' => 100_000,
            'peak_market_cap' => 100_000,
            'entry_at' => now()->subMinute(),
            'status' => 'open',
            'initial_investment_sol' => 0.1,
            'remaining_investment_sol' => 0.1,
            'remaining_fraction' => 1,
            'exit_events' => [],
            'strategy_snapshot' => $strategy,
        ]);
    }

    private function trackAt(PaperPosition $position, float $multiple): void
    {
        $http = new Factory;
        Http::swap($http);

        Http::fake([
            'api.dexscreener.com/tokens/v1/'.$position->chain->dexScreenerId().'/*' => Http::response([
                $this->pairFor($position, $multiple),
            ]),
        ]);

        $this->artisan('tokens:paper-track')->assertSuccessful();
    }

    /** @return array<string, mixed> */
    private function pairFor(PaperPosition $position, float $multiple): array
    {
        return [
            'chainId' => $position->chain->dexScreenerId(),
            'dexId' => $position->chain === Chain::Solana ? 'raydium' : 'uniswap',
            'pairAddress' => 'pair-'.$position->id,
            'baseToken' => ['address' => $position->address, 'symbol' => $position->symbol],
            'quoteToken' => ['address' => 'quote', 'symbol' => $position->chain === Chain::Solana ? 'SOL' : 'WETH'],
            'priceUsd' => '1',
            'marketCap' => 100_000 * $multiple,
            'liquidity' => ['usd' => 50_000],
            'txns' => ['m5' => ['buys' => 1, 'sells' => 1]],
            'volume' => ['m5' => 1_000],
        ];
    }
}
