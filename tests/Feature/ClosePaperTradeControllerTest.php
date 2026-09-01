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

    public function test_unavailable_market_data_does_not_change_position_or_wallet(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.dexscreener.com/token-pairs/v1/solana/token-address' => Http::response([], 503),
        ]);

        $wallet = $this->createWallet();
        $position = $this->createPosition();

        $response = $this->post(route('paper-trades.close', $position));

        $response
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => str_starts_with($message, 'Could not fetch current Dex price:'));

        $this->assertSame('open', $position->fresh()->status);
        $this->assertSame(4.9, $wallet->fresh()->available_balance_sol);
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
