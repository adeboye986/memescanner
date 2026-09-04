<?php

namespace Tests\Feature;

use App\Models\PaperPosition;
use App\Models\PaperWallet;
use App\Models\SystemActivity;
use App\Models\TradeOpportunity;
use App\Models\User;
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
        $this->actingAs(User::factory()->create(['is_admin' => true]));
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
            ->assertSee('Admin Dashboard')
            ->assertSee('Quick Actions')
            ->assertSee('Run Momentum Scan')
            ->assertSee('Check Wallet Reconciliation')
            ->assertSee('Position Tracker')
            ->assertSee('Admin Personal Paper Wallets')
            ->assertSee('Legacy Admin Ledgers')
            ->assertSee('4.5000')
            ->assertSee('5.0000')
            ->assertSee('OPEN')
            ->assertSee('+0.7500 SOL')
            ->assertDontSee('UNFUNDED')
            ->assertDontSee('CLOSED')
            ->assertViewHas('positions', fn ($positions): bool => $positions->first()['model']->is($openPosition));
    }

    public function test_dashboard_prioritizes_current_manual_activity_and_lists_recent_failures(): void
    {
        PaperWallet::query()->create(['name' => 'default']);
        SystemActivity::factory()->create([
            'label' => 'FAILED ACTIVITY',
            'status' => 'failed',
            'exit_code' => 1,
            'output' => null,
            'error_message' => 'Scanner failed',
        ]);
        SystemActivity::factory()->create([
            'label' => 'SCHEDULED TRACKER',
            'triggered_by' => 'scheduler',
            'status' => 'running',
            'finished_at' => null,
        ]);
        SystemActivity::factory()->create([
            'label' => 'CURRENT MANUAL',
            'triggered_by' => 'manual',
            'status' => 'running',
            'finished_at' => null,
        ]);

        $response = $this->get(route('dashboard'));

        $response
            ->assertOk()
            ->assertSee('CURRENT MANUAL')
            ->assertSee('FAILED ACTIVITY')
            ->assertSee('SCHEDULED TRACKER')
            ->assertSee('Recent Activity')
            ->assertViewHas('currentActivity', fn (array $activity): bool => $activity['label'] === 'CURRENT MANUAL')
            ->assertViewHas('recentActivities', fn (array $activities): bool => count($activities) === 3);
    }

    public function test_dashboard_summarizes_recent_and_pending_opportunities(): void
    {
        PaperWallet::query()->create(['name' => 'default']);
        TradeOpportunity::factory()->create(['status' => 'pending_confirmation', 'qualified_at' => now()]);
        TradeOpportunity::factory()->create(['status' => 'executed', 'qualified_at' => now()]);
        TradeOpportunity::factory()->create(['status' => 'ignored', 'qualified_at' => now()->subDays(2)]);

        $this->get(route('dashboard'))
            ->assertSuccessful()
            ->assertSee('Opportunities')
            ->assertSee('1 pending confirmation')
            ->assertViewHas('opportunitySummary', ['recent' => 2, 'pending' => 1]);
    }

    public function test_admin_shell_renders_only_real_authorized_navigation(): void
    {
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Administrator navigation')
            ->assertSee('Scanner Control')
            ->assertSee('Tracker & Queue', false)
            ->assertSee('Opportunities')
            ->assertSee('Trade History')
            ->assertSee('Platform Settings')
            ->assertSee('Telegram Bot')
            ->assertSee('Sign Out')
            ->assertDontSee('KYC')
            ->assertDontSee('Support Tickets')
            ->assertDontSee('Billing');
    }

    public function test_normal_user_keeps_customer_layout_without_admin_navigation(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Administrator navigation')
            ->assertDontSee('Platform Settings')
            ->assertSee('Paper Trading Dashboard');
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
