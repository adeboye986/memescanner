<?php

namespace Tests\Feature;

use App\Models\PaperPosition;
use App\Models\PaperWallet;
use App\Models\User;
use App\Services\EthereumPaperMarketData;
use App\Services\PaperTradeExitService;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\RefreshesPaperTradingDatabase;
use Tests\TestCase;

class ClosePaperTradeControllerTest extends TestCase
{
    use RefreshesPaperTradingDatabase;

    private const TOKEN = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const POOL = '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const QUOTE = '0xcccccccccccccccccccccccccccccccccccccccc';

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshPaperTradingDatabase();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        config(['services.trading.paper_tracker_cache_store' => 'array']);
        Cache::store('array')->flush();
    }

    public function test_guarded_ethereum_manual_close_uses_a_fresh_validated_observation_and_updates_wallet_once(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.dexscreener.com/*' => Http::response([]),
            'api.geckoterminal.com/*' => Http::response(['data' => $this->geckoPool()]),
        ]);
        $this->mock(TelegramService::class)->shouldReceive('send')->once();
        $wallet = $this->createWallet('ethereum');
        $position = $this->createEthereumPosition();

        $this->post(route('paper-trades.close', $position))
            ->assertRedirect()
            ->assertSessionHas('success', 'ETHMEME was closed successfully.')
            ->assertSessionMissing('warning');

        $position->refresh();
        $wallet->refresh();
        $event = $position->exit_events[0];

        $this->assertSame('closed', $position->status);
        $this->assertSame(0.0, $position->remaining_fraction);
        $this->assertSame(0.0, (float) $position->remaining_investment_sol);
        $this->assertSame(0.2, (float) $position->realized_sol);
        $this->assertSame(0.1, (float) $position->trade_pnl_sol);
        $this->assertSame('manual_close', $event['type']);
        $this->assertSame('fresh_market', $event['price_source']);
        $this->assertFalse($event['execution_verified']);
        $this->assertNull($event['estimated_executable_fill']);
        $this->assertSame('observed_mark_without_slippage_or_depth', $event['fill_model']);
        $this->assertSame('provider_market_cap', $event['market_observation']['valuation_source']);
        $this->assertSame('geckoterminal', $event['market_observation']['provider']);
        $this->assertSame(self::POOL, $event['market_observation']['pair_address']);
        $this->assertEqualsWithDelta(50_000.0, (float) $event['market_observation']['liquidity_usd'], 0.000001);
        $this->assertTrue($event['market_observation']['simulation_allowed']);
        $this->assertSame($event['market_observation'], $position->meta['last_valid_market_observation']);
        $this->assertSame(5.1, $wallet->available_balance_sol);
        $this->assertSame(0.0, $wallet->invested_balance_sol);
        $this->assertSame(0.1, $wallet->realized_pnl_sol);
        Http::assertSentCount(2);
    }

    public function test_unavailable_providers_leave_position_and_wallet_unchanged(): void
    {
        $this->fakeUnavailableEthereumProviders();
        $wallet = $this->createWallet('ethereum');
        $position = $this->createEthereumPosition();

        $this->post(route('paper-trades.close', $position))
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'no_valid_provider_observation'));

        $this->assertOpenWithoutAccountingChanges($position, $wallet);
        Http::assertSentCount(3);
    }

    public function test_geckoterminal_cooldown_is_a_clear_rejection_and_does_not_count_as_a_request(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.dexscreener.com/*' => Http::response([])]);
        Cache::store('array')->put('paper-market.ethereum.geckoterminal.cooldown', true, 60);
        $wallet = $this->createWallet('ethereum');
        $position = $this->createEthereumPosition();

        $this->post(route('paper-trades.close', $position))
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'geckoterminal_cooldown'));

        $this->assertOpenWithoutAccountingChanges($position, $wallet);
        Http::assertSentCount(1);
    }

    public function test_stale_fetched_observation_cannot_authorize_close(): void
    {
        $this->freezeTime();
        Http::preventStrayRequests();
        $wallet = $this->createWallet('ethereum');
        $position = $this->createEthereumPosition();
        $this->mock(EthereumPaperMarketData::class)
            ->shouldReceive('fetchForManualClose')
            ->once()
            ->andReturn($this->marketBatch($position, [
                ...$this->freshMarketData(),
                'fetched_at' => now()->subSeconds(61)->toIso8601String(),
            ]));

        $this->post(route('paper-trades.close', $position))
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'stale_or_missing_fetch_time'));

        $this->assertOpenWithoutAccountingChanges($position, $wallet);
        Http::assertNothingSent();
    }

    public function test_wrong_token_and_pool_responses_cannot_authorize_close(): void
    {
        Http::preventStrayRequests();
        $pair = $this->ethereumPair();
        data_set($pair, 'baseToken.address', self::QUOTE);
        $pool = $this->geckoPool();
        data_set($pool, 'relationships.base_token.data.id', 'eth_'.self::QUOTE);
        data_set($pool, 'relationships.quote_token.data.id', 'eth_'.str_repeat('d', 40));
        Http::fake(function ($request) use ($pair, $pool) {
            if (str_contains($request->url(), 'dexscreener')) {
                return Http::response([$pair]);
            }

            return str_contains($request->url(), '/tokens/')
                ? Http::response(['data' => [$pool]])
                : Http::response(['data' => $pool]);
        });
        $wallet = $this->createWallet('ethereum');
        $position = $this->createEthereumPosition();

        $this->post(route('paper-trades.close', $position))
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'no_valid_provider_observation'));

        $this->assertOpenWithoutAccountingChanges($position, $wallet);
        Http::assertSentCount(3);
    }

    public function test_inadequate_liquidity_cannot_authorize_close(): void
    {
        config(['services.trading.paper_market.ethereum.minimum_liquidity_usd' => 1_000]);
        Http::preventStrayRequests();
        $pair = $this->ethereumPair();
        data_set($pair, 'liquidity.usd', 500);
        $pool = $this->geckoPool();
        data_set($pool, 'attributes.reserve_in_usd', '500');
        Http::fake(function ($request) use ($pair, $pool) {
            if (str_contains($request->url(), 'dexscreener')) {
                return Http::response([$pair]);
            }

            return str_contains($request->url(), '/tokens/')
                ? Http::response(['data' => [$pool]])
                : Http::response(['data' => $pool]);
        });
        $wallet = $this->createWallet('ethereum');
        $position = $this->createEthereumPosition();

        $this->post(route('paper-trades.close', $position))
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'liquidity_below_configured_minimum'));

        $this->assertOpenWithoutAccountingChanges($position, $wallet);
    }

    public function test_repeated_close_cannot_add_an_event_or_credit_wallet_twice(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.dexscreener.com/*' => Http::response([$this->ethereumPair()]),
        ]);
        $this->mock(TelegramService::class)->shouldReceive('send')->once();
        $wallet = $this->createWallet('ethereum');
        $position = $this->createEthereumPosition();

        $this->post(route('paper-trades.close', $position))->assertSessionHas('success');
        $balance = $wallet->fresh()->available_balance_sol;
        $this->post(route('paper-trades.close', $position))
            ->assertSessionHas('error', 'This paper position is already closed.');

        $this->assertCount(1, $position->fresh()->exit_events);
        $this->assertSame($balance, $wallet->fresh()->available_balance_sol);
        Http::assertSentCount(1);
    }

    public function test_historical_stop_loss_observation_cannot_authorize_close_when_current_fetch_fails(): void
    {
        $this->freezeTime();
        $this->fakeUnavailableEthereumProviders();
        $wallet = $this->createWallet('ethereum');
        $historical = [
            ...$this->freshMarketData(),
            'market_cap' => 70_000,
            'simulation_allowed' => true,
            'observed_multiple' => 0.7,
            'stop_loss_threshold_breached' => true,
        ];
        $position = $this->createEthereumPosition([
            'last_market_cap' => 70_000,
            'meta' => [
                'pair_address' => self::POOL,
                'last_valid_market_observation' => $historical,
                'last_valid_market_observation_at' => now()->subMinute()->toIso8601String(),
            ],
        ]);

        $this->post(route('paper-trades.close', $position))
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'not eligible for simulation'));

        $this->assertOpenWithoutAccountingChanges($position, $wallet);
        $this->assertSame($historical, $position->fresh()->meta['last_valid_market_observation']);
    }

    public function test_authorization_is_rechecked_against_the_locked_position(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.dexscreener.com/*' => Http::response([$this->ethereumPair()])]);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $wallet = $this->createWallet('ethereum', $owner);
        $position = $this->createEthereumPosition(['user_id' => $owner->id]);

        try {
            app(PaperTradeExitService::class)->closeManually($position, $other);
            $this->fail('The close should have been rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('You are no longer authorized to close this paper position.', $exception->getMessage());
        }

        $this->assertOpenWithoutAccountingChanges($position, $wallet);
    }

    public function test_already_closed_and_unfunded_positions_are_rejected_before_provider_requests(): void
    {
        Http::preventStrayRequests();
        $this->createWallet('ethereum');
        $closed = $this->createEthereumPosition(['status' => 'closed', 'closed_at' => now()]);

        $this->post(route('paper-trades.close', $closed))
            ->assertSessionHas('error', 'This paper position is already closed.');

        $unfunded = $this->createEthereumPosition([
            'address' => '0xdddddddddddddddddddddddddddddddddddddddd',
            'initial_investment_sol' => 0,
            'remaining_investment_sol' => 0,
        ]);
        $this->post(route('paper-trades.close', $unfunded))
            ->assertSessionHas('error', 'Only funded paper positions can be closed.');

        Http::assertNothingSent();
    }

    public function test_invalid_entry_market_cap_rejects_without_wallet_mutation_or_provider_request(): void
    {
        Http::preventStrayRequests();
        $wallet = $this->createWallet('ethereum');
        $position = $this->createEthereumPosition(['entry_market_cap' => 0]);

        $this->post(route('paper-trades.close', $position))
            ->assertSessionHas('error', 'The position has no valid entry market cap. Position was NOT closed.');

        $this->assertOpenWithoutAccountingChanges($position, $wallet);
        Http::assertNothingSent();
    }

    public function test_dashboard_visibly_renders_error_success_and_warning_flashes(): void
    {
        $this->withSession([
            'success' => 'Close succeeded.',
            'warning' => 'Fallback valuation used.',
            'error' => 'Close failed.',
        ])->get(route('dashboard'))
            ->assertSee('Close succeeded.')
            ->assertSee('Fallback valuation used.')
            ->assertSee('Close failed.');
    }

    private function fakeUnavailableEthereumProviders(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.dexscreener.com/*' => Http::response([], 503),
            'api.geckoterminal.com/*' => Http::response([], 503),
        ]);
    }

    private function assertOpenWithoutAccountingChanges(PaperPosition $position, PaperWallet $wallet): void
    {
        $this->assertSame('open', $position->fresh()->status);
        $this->assertSame([], $position->fresh()->exit_events ?? []);
        $this->assertSame(4.9, $wallet->fresh()->available_balance_sol);
        $this->assertSame(0.1, $wallet->fresh()->invested_balance_sol);
        $this->assertSame(0.0, $wallet->fresh()->realized_pnl_sol);
    }

    private function createWallet(string $chain, ?User $user = null): PaperWallet
    {
        return PaperWallet::query()->create([
            'user_id' => $user?->id,
            'name' => 'default',
            'chain' => $chain,
            'currency' => $chain === 'ethereum' ? 'ETH' : 'SOL',
            'starting_balance_sol' => 5,
            'available_balance_sol' => 4.9,
            'invested_balance_sol' => 0.1,
            'realized_pnl_sol' => 0,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function createEthereumPosition(array $attributes = []): PaperPosition
    {
        return PaperPosition::query()->create(array_replace_recursive([
            'chain' => 'ethereum',
            'address' => self::TOKEN,
            'symbol' => 'ETHMEME',
            'entry_market_cap' => 100_000,
            'entry_price' => 0.001,
            'last_market_cap' => 100_000,
            'last_price' => 0.001,
            'peak_market_cap' => 100_000,
            'status' => 'open',
            'entry_at' => now(),
            'initial_investment_sol' => 0.1,
            'remaining_investment_sol' => 0.1,
            'remaining_fraction' => 1,
            'meta' => ['pair_address' => self::POOL],
        ], $attributes));
    }

    /** @return array<string, mixed> */
    private function ethereumPair(): array
    {
        return [
            'chainId' => 'ethereum',
            'dexId' => 'uniswap',
            'pairAddress' => self::POOL,
            'baseToken' => ['address' => self::TOKEN, 'symbol' => 'ETHMEME'],
            'quoteToken' => ['address' => self::QUOTE, 'symbol' => 'WETH'],
            'priceUsd' => '0.002',
            'marketCap' => 200_000,
            'liquidity' => ['usd' => 50_000],
        ];
    }

    /** @return array<string, mixed> */
    private function geckoPool(): array
    {
        return [
            'id' => 'eth_'.self::POOL,
            'type' => 'pool',
            'attributes' => [
                'address' => self::POOL,
                'base_token_price_usd' => '0.002',
                'market_cap_usd' => '200000',
                'fdv_usd' => '200000',
                'reserve_in_usd' => '50000',
            ],
            'relationships' => [
                'base_token' => ['data' => ['id' => 'eth_'.self::TOKEN, 'type' => 'token']],
                'quote_token' => ['data' => ['id' => 'eth_'.self::QUOTE, 'type' => 'token']],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function freshMarketData(): array
    {
        return [
            'available' => true,
            'requested_token_is_base' => true,
            'requested_token_address' => self::TOKEN,
            'provider' => 'dexscreener',
            'pair_address' => self::POOL,
            'price_usd' => 0.002,
            'market_cap' => 200_000,
            'liquidity_usd' => 50_000,
            'fetched_at' => now()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function marketBatch(PaperPosition $position, array $marketData): array
    {
        return [
            'observations' => [$position->id => $marketData],
            'requests' => 1,
            'failures' => 0,
            'rate_limited' => false,
            'provider_errors' => [],
            'provider_skips' => [],
        ];
    }
}
