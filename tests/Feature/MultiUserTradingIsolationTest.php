<?php

namespace Tests\Feature;

use App\Chain;
use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Models\PaperPosition;
use App\Models\PaperWallet;
use App\Models\SystemActivity;
use App\Models\TradeOpportunity;
use App\Models\User;
use App\Services\OpportunityActionService;
use App\Services\PaperStrategyService;
use App\Services\PaperWalletService;
use App\Services\TradeOpportunityService;
use App\Services\UserTradingPreferenceService;
use DomainException;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\RefreshesPaperTradingDatabase;
use Tests\TestCase;

class MultiUserTradingIsolationTest extends TestCase
{
    use RefreshesPaperTradingDatabase;

    private User $userA;

    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshPaperTradingDatabase();
        $this->withoutVite();
        $this->userA = User::factory()->create(['is_admin' => false]);
        $this->userB = User::factory()->create(['is_admin' => false]);
    }

    public function test_solana_and_ethereum_wallets_are_isolated_per_user(): void
    {
        $wallets = app(PaperWalletService::class);
        $aSol = $wallets->forUser($this->userA, Chain::Solana);
        $bSol = $wallets->forUser($this->userB, Chain::Solana);
        $aEth = $wallets->forUser($this->userA, Chain::Ethereum);
        $bEth = $wallets->forUser($this->userB, Chain::Ethereum);
        $aSol->update(['available_balance_sol' => 4]);
        $aEth->update(['available_balance_sol' => 3]);

        $this->assertNotSame($aSol->id, $bSol->id);
        $this->assertSame(5.0, $bSol->fresh()->available_balance_sol);
        $this->assertSame(5.0, $bEth->fresh()->available_balance_sol);
        $this->assertSame(3.0, $aEth->fresh()->available_balance_sol);
    }

    public function test_same_discovery_creates_independent_opportunities_with_independent_modes(): void
    {
        $preferences = app(UserTradingPreferenceService::class);
        $preferences->update($this->userA, ExecutionMode::Paper, EntryMode::Confirm);
        $preferences->update($this->userB, ExecutionMode::Paper, EntryMode::Signal);
        app(TradeOpportunityService::class)->qualify($this->candidate());

        $this->assertDatabaseHas('trade_opportunities', ['user_id' => $this->userA->id, 'status' => 'pending_confirmation']);
        $this->assertDatabaseHas('trade_opportunities', ['user_id' => $this->userB->id, 'status' => 'qualified']);
        $this->assertDatabaseCount('paper_positions', 0);
    }

    public function test_cross_user_opportunity_read_approve_and_ignore_are_denied(): void
    {
        $opportunity = TradeOpportunity::factory()->create(['user_id' => $this->userB->id, 'status' => 'pending_confirmation']);
        $this->actingAs($this->userA)->get(route('opportunities.show', $opportunity))->assertNotFound();
        $this->post(route('opportunities.approve', $opportunity))->assertNotFound();
        $this->post(route('opportunities.ignore', $opportunity))->assertNotFound();
        $this->expectException(DomainException::class);
        app(OpportunityActionService::class)->ignore($opportunity, $this->userA);
    }

    public function test_cross_user_position_read_and_close_are_denied(): void
    {
        $position = $this->position($this->userB);
        $this->actingAs($this->userA)->get(route('dashboard'))->assertOk()->assertDontSee($position->symbol);
        $this->post(route('paper-trades.close', $position))->assertNotFound();
        $this->assertSame('open', $position->fresh()->status);
    }

    public function test_modes_strategies_and_snapshots_are_independent(): void
    {
        $preferences = app(UserTradingPreferenceService::class);
        $preferences->update($this->userA, ExecutionMode::Paper, EntryMode::Auto);
        $preferences->update($this->userB, ExecutionMode::Paper, EntryMode::Confirm);
        $strategies = app(PaperStrategyService::class);
        $strategies->updateForUser($this->userA, $this->strategy(15, 80, 160));
        $strategies->updateForUser($this->userB, $this->strategy(20, 120, 240));
        $result = app(TradeOpportunityService::class)->qualify($this->candidate('auto-a'), $this->userA);
        $strategies->updateForUser($this->userA, $this->strategy(25, 150, 300));

        $this->assertSame(EntryMode::Auto, $preferences->forUser($this->userA)->entry_mode);
        $this->assertSame(EntryMode::Confirm, $preferences->forUser($this->userB)->entry_mode);
        $this->assertEquals(15.0, $result['position']->fresh()->strategy_snapshot['stop_loss_percent']);
        $this->assertEquals(20.0, $strategies->forUser($this->userB)['stop_loss_percent']);
    }

    public function test_auto_retry_is_idempotent_and_debits_only_owner(): void
    {
        app(UserTradingPreferenceService::class)->update($this->userA, ExecutionMode::Paper, EntryMode::Auto);
        $first = app(TradeOpportunityService::class)->qualify($this->candidate('retry'), $this->userA);
        $second = app(TradeOpportunityService::class)->qualify($this->candidate('retry'), $this->userA);

        $this->assertTrue($first['position']->is($second['position']));
        $this->assertDatabaseCount('paper_positions', 1);
        $this->assertSame(4.9, app(PaperWalletService::class)->forUser($this->userA, Chain::Solana)->available_balance_sol);
        $this->assertSame(5.0, app(PaperWalletService::class)->forUser($this->userB, Chain::Solana)->available_balance_sol);
    }

    public function test_user_scoped_scan_qualification_cannot_execute_for_another_user(): void
    {
        app(UserTradingPreferenceService::class)->update($this->userA, ExecutionMode::Paper, EntryMode::Auto);
        app(UserTradingPreferenceService::class)->update($this->userB, ExecutionMode::Paper, EntryMode::Auto);
        app(TradeOpportunityService::class)->qualify($this->candidate('manual-a'), $this->userA);

        $this->assertDatabaseHas('trade_opportunities', ['user_id' => $this->userA->id]);
        $this->assertDatabaseMissing('trade_opportunities', ['user_id' => $this->userB->id]);
        $this->assertDatabaseMissing('paper_positions', ['user_id' => $this->userB->id]);
    }

    public function test_tracker_exit_credits_only_position_owner(): void
    {
        $walletA = app(PaperWalletService::class)->forUser($this->userA, Chain::Solana);
        $walletA->update(['available_balance_sol' => 4.9, 'invested_balance_sol' => 0.1]);
        $walletB = app(PaperWalletService::class)->forUser($this->userB, Chain::Solana);
        $position = $this->position($this->userA, ['strategy_snapshot' => [...$this->strategy(10, 100, 200), 'source' => 'user']]);
        Http::fake(['api.dexscreener.com/tokens/v1/solana/*' => Http::response([$this->pair($position, 0.8)])]);
        $this->artisan('tokens:paper-track')->assertSuccessful();

        $this->assertSame('closed', $position->fresh()->status);
        $this->assertEqualsWithDelta(4.98, $walletA->fresh()->available_balance_sol, 0.000001);
        $this->assertSame(5.0, $walletB->fresh()->available_balance_sol);
    }

    public function test_normal_user_cannot_access_legacy_rows_but_admin_can(): void
    {
        $legacy = TradeOpportunity::factory()->create(['user_id' => null, 'symbol' => 'LEGACY']);
        $this->actingAs($this->userA)->get(route('opportunities.show', $legacy))->assertNotFound();
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('opportunities.show', $legacy))->assertOk()->assertSee('LEGACY');
    }

    public function test_admin_can_access_legacy_rows_but_not_another_users_rows(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $otherOpportunity = TradeOpportunity::factory()->create(['user_id' => $this->userB->id, 'symbol' => 'OTHER-OPP']);
        $otherPosition = $this->position($this->userB, ['symbol' => 'OTHER-POS']);

        $this->actingAs($admin)->get(route('opportunities.show', $otherOpportunity))->assertNotFound();
        $this->get(route('dashboard'))->assertOk()->assertDontSee('OTHER-POS');
        $this->get(route('trades.index'))->assertOk()->assertDontSee('OTHER-POS');
        $this->assertSame('open', $otherPosition->fresh()->status);
    }

    public function test_admin_dashboard_keeps_personal_and_legacy_wallet_ledgers_separate(): void
    {
        PaperWallet::query()->create(['user_id' => null, 'name' => 'default', 'chain' => 'solana', 'currency' => 'SOL', 'starting_balance_sol' => 5, 'available_balance_sol' => 1.2345, 'invested_balance_sol' => 3.7655, 'realized_pnl_sol' => 0]);
        $admin = User::factory()->create(['is_admin' => true]);
        app(PaperWalletService::class)->forUser($admin, Chain::Solana)->update(['available_balance_sol' => 4.5]);

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Legacy Admin Ledgers')
            ->assertSee('1.2345 SOL')
            ->assertSee('4.5000 SOL');
    }

    public function test_dashboard_activity_endpoint_is_authenticated_and_user_scoped(): void
    {
        SystemActivity::factory()->create(['user_id' => $this->userA->id, 'label' => 'USER A ACTIVITY']);
        SystemActivity::factory()->create(['user_id' => $this->userB->id, 'label' => 'USER B ACTIVITY']);

        $this->get(route('dashboard.activity'))->assertRedirect(route('login'));
        $this->actingAs($this->userA)
            ->getJson(route('dashboard.activity'))
            ->assertOk()
            ->assertJsonFragment(['label' => 'USER A ACTIVITY'])
            ->assertJsonMissing(['label' => 'USER B ACTIVITY']);
    }

    public function test_normal_user_cannot_trigger_platform_wide_manual_tracker(): void
    {
        $this->actingAs($this->userA)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Track Positions Now');

        $this->post(route('dashboard.actions.store', 'paper-track'))->assertForbidden();
        $this->assertDatabaseMissing('system_activities', ['action' => 'paper-track']);
    }

    private function candidate(string $address = 'shared-discovery'): array
    {
        return ['chain' => 'solana', 'address' => $address, 'symbol' => strtoupper($address), 'entry_market_cap' => 100_000, 'entry_price' => 0.001, 'entry_liquidity' => 20_000, 'scanner' => 'test', 'send_notification' => false];
    }

    private function position(User $user, array $overrides = []): PaperPosition
    {
        return PaperPosition::query()->create(array_merge(['user_id' => $user->id, 'chain' => 'solana', 'address' => 'position-'.$user->id, 'symbol' => 'PRIVATE-'.$user->id, 'entry_market_cap' => 100_000, 'last_market_cap' => 100_000, 'peak_market_cap' => 100_000, 'entry_at' => now()->subMinute(), 'status' => 'open', 'initial_investment_sol' => 0.1, 'remaining_investment_sol' => 0.1, 'remaining_fraction' => 1, 'exit_events' => []], $overrides));
    }

    private function strategy(float $stop, float $one, float $two): array
    {
        return ['stop_loss_percent' => $stop, 'protection_level_1_percent' => $one, 'protection_level_2_percent' => $two];
    }

    private function pair(PaperPosition $position, float $multiple): array
    {
        return ['chainId' => 'solana', 'dexId' => 'raydium', 'pairAddress' => 'pair', 'baseToken' => ['address' => $position->address, 'symbol' => $position->symbol], 'quoteToken' => ['address' => 'sol', 'symbol' => 'SOL'], 'priceUsd' => '1', 'marketCap' => 100_000 * $multiple, 'liquidity' => ['usd' => 50_000], 'txns' => ['m5' => ['buys' => 1, 'sells' => 1]], 'volume' => ['m5' => 1_000]];
    }
}
