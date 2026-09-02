<?php

namespace Tests\Feature;

use App\Models\PaperPosition;
use App\Models\PaperWallet;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\RefreshesPaperTradingDatabase;
use Tests\TestCase;

class ClosePaperTradeControllerTest extends TestCase
{
    use RefreshesPaperTradingDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshPaperTradingDatabase();
    }

    public function test_manual_close_updates_position_and_wallet_and_records_exit(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.dexscreener.com/token-pairs/v1/solana/token-address' => Http::response([
                [
                    'baseToken' => ['address' => 'token-address', 'symbol' => 'MEME'],
                    'quoteToken' => ['address' => 'sol', 'symbol' => 'SOL'],
                    'marketCap' => 200_000,
                    'priceUsd' => '0.002',
                    'liquidity' => ['usd' => 50_000],
                ],
            ]),
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $wallet = $this->createWallet();
        $position = $this->createPosition();

        $response = $this->post(route('paper-trades.close', $position));

        $response
            ->assertRedirect()
            ->assertSessionHas('success', 'MEME was closed successfully.');

        $position->refresh();
        $wallet->refresh();

        $this->assertSame('closed', $position->status);
        $this->assertSame(0.0, $position->remaining_fraction);
        $this->assertSame(0.0, (float) $position->remaining_investment_sol);
        $this->assertSame(0.2, (float) $position->realized_sol);
        $this->assertSame(0.1, (float) $position->trade_pnl_sol);
        $this->assertSame('manual_close', $position->exit_events[0]['type']);
        $this->assertSame('fresh_market', $position->exit_events[0]['price_source']);
        $this->assertNull($position->exit_events[0]['fresh_market_error']);
        $this->assertNotNull($position->closed_at);
        $this->assertSame(5.1, $wallet->available_balance_sol);
        $this->assertSame(0.0, $wallet->invested_balance_sol);
        $this->assertSame(0.1, $wallet->realized_pnl_sol);

        Http::assertSentCount(2);
    }

    public function test_already_closed_position_cannot_be_closed_again(): void
    {
        Http::preventStrayRequests();
        $this->createWallet();
        $position = $this->createPosition(['status' => 'closed', 'closed_at' => now()]);

        $response = $this->post(route('paper-trades.close', $position));

        $response
            ->assertRedirect()
            ->assertSessionHas('error', 'This paper position is already closed.');

        Http::assertNothingSent();
    }

    public function test_unfunded_position_cannot_be_closed(): void
    {
        Http::preventStrayRequests();
        $this->createWallet();
        $position = $this->createPosition([
            'initial_investment_sol' => 0,
            'remaining_investment_sol' => 0,
        ]);

        $response = $this->post(route('paper-trades.close', $position));

        $response
            ->assertRedirect()
            ->assertSessionHas('error', 'Only funded paper positions can be closed.');

        Http::assertNothingSent();
    }

    public function test_unavailable_fresh_market_data_closes_at_last_known_market_value(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.dexscreener.com/token-pairs/v1/solana/token-address' => Http::response([], 503),
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $wallet = $this->createWallet();
        $position = $this->createPosition();

        $response = $this->post(route('paper-trades.close', $position));

        $response
            ->assertRedirect()
            ->assertSessionHas('success', 'MEME was closed successfully using its last known market value because fresh Dex data was unavailable.')
            ->assertSessionHas('warning');

        $event = $position->fresh()->exit_events[0];
        $this->assertSame('closed', $position->fresh()->status);
        $this->assertSame('last_known_market', $event['price_source']);
        $this->assertStringStartsWith('Could not fetch current market data:', $event['fresh_market_error']);
        $this->assertSame(5.0, $wallet->fresh()->available_balance_sol);
        $this->assertSame(0.0, $wallet->fresh()->invested_balance_sol);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api.telegram.org')
            && str_contains((string) $request['text'], 'Price source:</b> Last known market'));
    }

    public function test_invalid_fresh_market_cap_uses_last_known_market_value(): void
    {
        Http::fake([
            'https://api.dexscreener.com/token-pairs/v1/solana/token-address' => Http::response([[
                'baseToken' => ['address' => 'token-address', 'symbol' => 'MEME'],
                'quoteToken' => ['address' => 'sol', 'symbol' => 'SOL'],
                'marketCap' => 0,
                'priceUsd' => '0',
                'liquidity' => ['usd' => 1],
            ]]),
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);
        $wallet = $this->createWallet();
        $position = $this->createPosition(['last_market_cap' => 80_000, 'last_price' => 0.0008]);

        $this->post(route('paper-trades.close', $position))->assertSessionHas('warning');

        $event = $position->fresh()->exit_events[0];
        $this->assertSame('last_known_market', $event['price_source']);
        $this->assertSame('Current market data returned an invalid market cap.', $event['fresh_market_error']);
        $this->assertEqualsWithDelta(0.8, $event['fill_multiple'], 0.000001);
        $this->assertEqualsWithDelta(4.98, $wallet->fresh()->available_balance_sol, 0.000001);
    }

    public function test_missing_last_market_cap_uses_entry_fallback(): void
    {
        Http::fake([
            'https://api.dexscreener.com/token-pairs/v1/solana/token-address' => Http::response([]),
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);
        $wallet = $this->createWallet();
        $position = $this->createPosition(['last_market_cap' => null, 'last_price' => null, 'entry_price' => 0.001]);

        $this->post(route('paper-trades.close', $position))
            ->assertSessionHas('success', 'MEME was closed successfully using its entry value because fresh and last known market data were unavailable.')
            ->assertSessionHas('warning');

        $event = $position->fresh()->exit_events[0];
        $this->assertSame('entry_fallback', $event['price_source']);
        $this->assertEqualsWithDelta(1.0, $event['fill_multiple'], 0.000001);
        $this->assertEqualsWithDelta(5.0, $wallet->fresh()->available_balance_sol, 0.000001);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'api.telegram.org')
            && str_contains((string) $request['text'], 'Price source:</b> Entry fallback'));
    }

    public function test_no_usable_market_cap_rejects_without_wallet_mutation(): void
    {
        Http::preventStrayRequests();
        $wallet = $this->createWallet();
        $position = $this->createPosition(['entry_market_cap' => 0, 'last_market_cap' => null]);

        $this->post(route('paper-trades.close', $position))
            ->assertSessionHas('error', 'No valid fresh, last-known, or entry market-cap data is available. Position was NOT closed.');

        $this->assertSame('open', $position->fresh()->status);
        $this->assertSame([], $position->fresh()->exit_events ?? []);
        $this->assertSame(4.9, $wallet->fresh()->available_balance_sol);
        $this->assertSame(0.1, $wallet->fresh()->invested_balance_sol);
        Http::assertNothingSent();
    }

    public function test_repeated_fallback_close_cannot_add_an_event_or_credit_wallet_twice(): void
    {
        Http::fake([
            'https://api.dexscreener.com/token-pairs/v1/solana/token-address' => Http::response([]),
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);
        $wallet = $this->createWallet();
        $position = $this->createPosition(['last_market_cap' => 50_000]);

        $this->post(route('paper-trades.close', $position))->assertSessionHas('success');
        $balance = $wallet->fresh()->available_balance_sol;
        $this->post(route('paper-trades.close', $position))->assertSessionHas('error', 'This paper position is already closed.');

        $this->assertCount(1, $position->fresh()->exit_events);
        $this->assertSame($balance, $wallet->fresh()->available_balance_sol);
    }

    public function test_ethereum_fallback_close_updates_only_the_ethereum_wallet(): void
    {
        Http::fake([
            'https://api.dexscreener.com/token-pairs/v1/ethereum/0xabc' => Http::response([]),
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);
        $solanaWallet = $this->createWallet();
        $ethereumWallet = PaperWallet::query()->create([
            'name' => 'default', 'chain' => 'ethereum', 'currency' => 'ETH',
            'starting_balance_sol' => 5, 'available_balance_sol' => 4.9,
            'invested_balance_sol' => 0.1, 'realized_pnl_sol' => 0,
        ]);
        $position = $this->createPosition([
            'chain' => 'ethereum', 'address' => '0xabc', 'symbol' => 'ETHMEME',
            'last_market_cap' => 150_000,
        ]);

        $this->post(route('paper-trades.close', $position))->assertSessionHas('warning');

        $this->assertEqualsWithDelta(4.9, $solanaWallet->fresh()->available_balance_sol, 0.000001);
        $this->assertEqualsWithDelta(5.05, $ethereumWallet->fresh()->available_balance_sol, 0.000001);
        $this->assertSame('last_known_market', $position->fresh()->exit_events[0]['price_source']);
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

    private function createWallet(): PaperWallet
    {
        return PaperWallet::query()->create([
            'name' => 'default',
            'starting_balance_sol' => 5,
            'available_balance_sol' => 4.9,
            'invested_balance_sol' => 0.1,
            'realized_pnl_sol' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createPosition(array $attributes = []): PaperPosition
    {
        return PaperPosition::query()->create(array_merge([
            'address' => 'token-address',
            'symbol' => 'MEME',
            'entry_market_cap' => 100_000,
            'last_market_cap' => 100_000,
            'peak_market_cap' => 100_000,
            'status' => 'open',
            'entry_at' => now(),
            'initial_investment_sol' => 0.1,
            'remaining_investment_sol' => 0.1,
            'remaining_fraction' => 1,
        ], $attributes));
    }
}
