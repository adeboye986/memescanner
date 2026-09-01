<?php

namespace Tests\Feature;

use App\Models\PaperPosition;
use App\Models\PaperWallet;
use Tests\Concerns\RefreshesPaperTradingDatabase;
use Tests\TestCase;

class PaperTradingDashboardTest extends TestCase
{
    use RefreshesPaperTradingDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshPaperTradingDatabase();
        $this->withoutVite();
    }

    public function test_dashboard_renders_wallet_and_only_funded_open_positions(): void
    {
        PaperWallet::query()->create([
            'name' => 'default',
            'starting_balance_sol' => 5,
            'available_balance_sol' => 4.5,
            'invested_balance_sol' => 0.5,
            'realized_pnl_sol' => 0.25,
        ]);

        $openPosition = $this->createPosition([
            'symbol' => 'OPEN',
            'entry_market_cap' => 100_000,
            'last_market_cap' => 250_000,
            'peak_market_cap' => 260_000,
            'initial_investment_sol' => 0.5,
            'remaining_investment_sol' => 0.5,
            'tp_50_hit' => true,
        ]);

        $this->createPosition(['symbol' => 'UNFUNDED', 'initial_investment_sol' => 0]);
        $this->createPosition(['symbol' => 'CLOSED', 'status' => 'closed']);

        $response = $this->get(route('dashboard'));

        $response
            ->assertOk()
            ->assertSee('Paper Trading Dashboard')
            ->assertSee('Scanner Controls')
            ->assertSee('Run Momentum Scan')
            ->assertSee('Check Wallet Reconciliation')
            ->assertSee('Auto Tracker:')
            ->assertSee('UNKNOWN')
            ->assertDontSee('Auto Tracking: Active')
            ->assertSee('4.5000 SOL')
            ->assertSee('OPEN')
            ->assertSee('+150.00%')
            ->assertSee('Protection armed')
            ->assertSee('$95,000.00')
            ->assertSee('$300,000.00')
            ->assertDontSee('UNFUNDED')
            ->assertDontSee('CLOSED')
            ->assertViewHas('positions', fn ($positions): bool => $positions->first()['model']->is($openPosition));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createPosition(array $attributes = []): PaperPosition
    {
        return PaperPosition::query()->create(array_merge([
            'address' => 'token-'.fake()->uuid(),
            'symbol' => 'TOKEN',
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
