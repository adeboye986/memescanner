<?php

namespace Tests\Feature;

use App\Chain;
use App\Models\PaperPosition;
use App\Models\PaperWallet;
use App\Services\TelegramService;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
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

    private function createPosition(Chain $chain, ?string $address = null): PaperPosition
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
        ]);
    }

    private function trackAt(PaperPosition $position, float $multiple): void
    {
        $http = new Factory;
        Http::swap($http);

        Http::fake([
            'api.dexscreener.com/token-pairs/v1/'.$position->chain->dexScreenerId().'/*' => Http::response([[
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
            ]]),
        ]);

        $this->artisan('tokens:paper-track')->assertSuccessful();
    }
}
