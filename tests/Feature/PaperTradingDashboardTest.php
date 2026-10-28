<?php

namespace Tests\Feature;

use App\Models\PaperPosition;
use App\Models\PaperWallet;
use App\Models\SystemActivity;
use App\Models\TradeOpportunity;
use App\Models\User;
use App\Services\ApplicationSettingsService;
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

        $response = $this->get(route('dashboard', ['view' => 'tools']));

        $response
            ->assertOk()
            ->assertSee('Admin Dashboard')
            ->assertSee('Quick Actions')
            ->assertSee('Run Momentum Scan')
            ->assertSee('Check Wallet Reconciliation')
            ->assertSee('Legacy Laravel PAPER Tracker')
            ->assertSee('Legacy Laravel Admin PAPER Wallets')
            ->assertSee('TypeScript Trading Engine')
            ->assertSee('PostgreSQL Workflow Leader')
            ->assertSee('NOT AVAILABLE')
            ->assertSee('Engine-owned PAPER portfolio')
            ->assertSee('Legacy Admin Ledgers')
            ->assertSee('4.5000')
            ->assertSee('5.0000')
            ->assertSee('OPEN')
            ->assertSee('+0.7500 SOL')
            ->assertDontSee('UNFUNDED')
            ->assertDontSee('CLOSED')
            ->assertViewHas('positions', fn ($positions): bool => $positions->first()['model']->is($openPosition));
    }

    public function test_dashboard_labels_saved_valid_observation_as_historical_with_timestamp(): void
    {
        $timestamp = '2026-09-24T12:00:00+00:00';
        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user);
        $this->createPosition(['user_id' => $user->id, 'chain' => 'ethereum', 'meta' => [
            'market_observation' => ['status' => 'unverified', 'reasons' => ['geckoterminal_cooldown'], 'valuation_source' => 'unavailable'],
            'last_valid_market_observation' => ['provider' => 'dexscreener', 'market_cap' => 123456],
            'last_valid_market_observation_at' => $timestamp,
        ]]);

        $this->get(route('dashboard', ['view' => 'tools']))->assertSuccessful()
            ->assertSeeText('automatic PAPER exit deferred: geckoterminal_cooldown')
            ->assertSeeText('Historical validated observation (not current): '.$timestamp)
            ->assertSeeText('dexscreener')
            ->assertSeeText('Market cap: USD 123,456.00');
    }

    public function test_dashboard_distinguishes_missing_historical_market_cap_from_numeric_zero(): void
    {
        $timestamp = '2026-09-24T12:00:00+00:00';
        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user);
        foreach ([['symbol' => 'MISSING', 'observation' => ['provider' => 'geckoterminal']],
            ['symbol' => 'ZERO', 'observation' => ['provider' => 'geckoterminal', 'market_cap' => 0]]] as $case) {
            $this->createPosition(['user_id' => $user->id, 'symbol' => $case['symbol'], 'chain' => 'ethereum', 'meta' => [
                'last_valid_market_observation' => $case['observation'],
                'last_valid_market_observation_at' => $timestamp,
            ]]);
        }

        $this->get(route('dashboard', ['view' => 'tools']))->assertSuccessful()
            ->assertSeeText('Historical validated observation (not current): '.$timestamp)
            ->assertSeeText('Market cap: Unavailable')
            ->assertSeeText('Market cap: USD 0.00');
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
            ->assertDontSee('Scanner failed')
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

    public function test_overview_matches_the_approved_information_hierarchy_with_authorized_data(): void
    {
        $this->travelTo('2026-10-28 09:30:00');
        auth()->user()->update(['name' => 'Daniel Scout']);

        $this->createPosition(['symbol' => 'PRIVATE-POSITION']);
        TradeOpportunity::factory()->count(2)->create(['qualified_at' => now()]);
        SystemActivity::factory()->count(2)->create([
            'user_id' => auth()->id(),
            'action' => 'momentum-scan',
            'command' => 'tokens:momentum',
            'label' => 'Momentum Scan',
        ]);
        SystemActivity::factory()->create([
            'user_id' => auth()->id(),
            'action' => 'token-scan',
            'command' => 'tokens:scan-new',
            'label' => 'New Token Scan — Solana',
            'chain' => 'solana',
            'result_summary' => [
                'version' => 1,
                'kind' => 'scanner_execution',
                'scanner' => 'new-token',
                'chain' => 'solana',
                'outcome' => 'succeeded_with_warnings',
                'counts' => [
                    'discovered' => 20,
                    'examined' => 20,
                    'qualified' => 0,
                    'opportunities_created' => 0,
                    'opportunities_existing' => 0,
                    'skipped' => 12,
                    'failed' => 8,
                ],
                'provider_failures' => [
                    ['code' => 'PROVIDER_RATE_LIMITED', 'count' => 1],
                ],
            ],
        ]);

        $response = $this->get(route('dashboard'));

        $response
            ->assertOk()
            ->assertViewIs('dashboard.overview')
            ->assertSee('data-overview-screen', false)
            ->assertSeeText('Good morning, Daniel!')
            ->assertSeeTextInOrder(['Total Scans', '3', 'New Opportunities', '2', 'Legacy Positions', '1', 'Engine Positions', '0'])
            ->assertSeeTextInOrder(['Latest Scan', 'Completed with warnings', 'Examined', '20', 'Qualified', '0', 'Failed', '8', 'Skipped', '12', 'Provider incidents', '1'])
            ->assertSeeText('PROVIDER_RATE_LIMITED')
            ->assertSeeText('Recent Activity')
            ->assertSeeText('Quick Actions')
            ->assertSeeText('Run New Scan')
            ->assertDontSeeText('Execution Preference')
            ->assertDontSeeText('Paper Strategy')
            ->assertDontSeeText('Legacy Laravel Admin PAPER Wallets')
            ->assertDontSeeText('Background Process Health');
    }

    public function test_overview_renders_unreported_scanner_metrics_as_unavailable_instead_of_zero(): void
    {
        SystemActivity::factory()->create([
            'user_id' => auth()->id(),
            'action' => 'token-scan',
            'command' => 'tokens:scan-new',
            'label' => 'New Token Scan — Solana',
            'chain' => 'solana',
            'result_summary' => [
                'version' => 1,
                'kind' => 'scanner_execution',
                'scanner' => 'new-token',
                'chain' => 'solana',
                'outcome' => 'succeeded',
                'counts' => ['examined' => 0],
            ],
        ]);

        $response = $this->get(route('dashboard'));

        $response
            ->assertOk()
            ->assertSeeText('Completed')
            ->assertSee('aria-label="Not reported"', false);
        $this->assertSame(4, substr_count($response->getContent(), 'aria-label="Not reported"'));
    }

    public function test_admin_shell_renders_only_real_authorized_navigation(): void
    {
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('aria-label="Application navigation"', false)
            ->assertSee('Token Scanner')
            ->assertSee('Opportunities')
            ->assertSee('Trading')
            ->assertSee('Wallets')
            ->assertSee('Settings')
            ->assertSee('System status')
            ->assertSee('Administrator')
            ->assertSee('Sign out')
            ->assertDontSee('KYC')
            ->assertDontSee('Support Tickets')
            ->assertDontSee('Billing');
    }

    public function test_normal_user_uses_shared_shell_without_admin_only_destinations(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('aria-label="Application navigation"', false)
            ->assertSee('Token Scanner')
            ->assertSee('Wallets')
            ->assertSee('Settings')
            ->assertSee('Trader')
            ->assertDontSee('Administrator')
            ->assertDontSee('Platform Settings')
            ->assertSee('Latest Scan')
            ->assertSee('Recent Activity')
            ->assertSee('Quick Actions')
            ->assertDontSee('Execution Preference')
            ->assertDontSee('Paper Trading Dashboard');
    }

    public function test_authenticated_shell_exposes_accessible_theme_and_navigation_controls_and_escapes_identity(): void
    {
        $user = User::factory()->create([
            'is_admin' => false,
            'name' => '<script>alert("shell")</script>',
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response
            ->assertOk()
            ->assertSee('meme-scanner-theme')
            ->assertSee('data-theme-toggle', false)
            ->assertSee('data-sidebar-close', false)
            ->assertSee('aria-label="Switch color theme"', false)
            ->assertSee('placeholder="Search navigation..."', false)
            ->assertSee('aria-label="Notification settings"', false)
            ->assertSee('<span class="ms-brand-name">Meme Scanner</span>', false)
            ->assertDontSee('<span class="ms-brand-name">Laravel</span>', false)
            ->assertSee($user->name)
            ->assertDontSee($user->name, false);
    }

    public function test_authenticated_shell_preserves_a_deliberately_customized_application_name(): void
    {
        app(ApplicationSettingsService::class)->update([
            'general.application_name' => 'Scanner Operations',
        ]);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('<span class="ms-brand-name">Scanner Operations</span>', false)
            ->assertDontSee('<span class="ms-brand-name">Laravel</span>', false);
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
