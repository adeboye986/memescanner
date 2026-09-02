<?php

namespace Tests\Feature;

use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Enums\TradeOpportunityStatus;
use App\Models\PaperWallet;
use App\Models\TradeOpportunity;
use App\Models\User;
use App\Services\ApplicationSettingsService;
use App\Services\TelegramService;
use App\Services\TradeOpportunityService;
use Tests\Concerns\RefreshesPaperTradingDatabase;
use Tests\TestCase;

class OpportunityWorkflowTest extends TestCase
{
    use RefreshesPaperTradingDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshPaperTradingDatabase();
        $this->admin = User::factory()->create(['is_admin' => true]);
        PaperWallet::query()->create(['name' => 'default', 'starting_balance_sol' => 5, 'available_balance_sol' => 5, 'invested_balance_sol' => 0, 'realized_pnl_sol' => 0]);
        $this->mock(TelegramService::class)->shouldReceive('send')->zeroOrMoreTimes();
    }

    public function test_opportunity_pages_require_authentication_and_admin_authorization(): void
    {
        $opportunity = TradeOpportunity::factory()->create();
        $this->get(route('opportunities.index'))->assertRedirect(route('login'));
        $this->get(route('opportunities.show', $opportunity))->assertRedirect(route('login'));
        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user)->get(route('opportunities.index'))->assertForbidden();
        $this->post(route('opportunities.ignore', $opportunity))->assertForbidden();
    }

    public function test_list_filters_and_detail_render_stored_opportunity_data(): void
    {
        $shown = TradeOpportunity::factory()->create(['chain' => 'ethereum', 'symbol' => 'DEMO', 'name' => 'Demo Coin', 'address' => '0x12345678901234567890', 'status' => TradeOpportunityStatus::PendingConfirmation, 'entry_mode' => EntryMode::Confirm, 'market_cap' => 12000, 'liquidity' => 5000, 'security_data' => ['score' => 97]]);
        TradeOpportunity::factory()->create(['status' => TradeOpportunityStatus::Ignored]);

        $this->actingAs($this->admin)->get(route('opportunities.index', ['status' => 'pending_confirmation', 'chain' => 'ethereum', 'entry_mode' => 'confirm']))
            ->assertSuccessful()->assertSee('DEMO');
        $this->get(route('opportunities.show', $shown))->assertSuccessful()->assertSee('Demo Coin')->assertSee('0x12345678901234567890')->assertSee('Approve Opportunity')->assertSee('Ethereum Security Coverage')->assertDontSee('97');
    }

    public function test_signal_is_visible_but_never_creates_a_position(): void
    {
        app(ApplicationSettingsService::class)->update(['trading.entry_mode' => 'signal']);
        $result = app(TradeOpportunityService::class)->qualify($this->candidate());

        $this->assertNull($result['position']);
        $this->assertSame(TradeOpportunityStatus::Qualified, $result['opportunity']->status);
        $this->assertDatabaseCount('paper_positions', 0);
        $this->actingAs($this->admin)->get(route('opportunities.show', $result['opportunity']))->assertSee('Signal only')->assertDontSee('Approve Opportunity');
    }

    public function test_paper_confirm_approval_creates_exactly_one_position_and_debits_once(): void
    {
        $opportunity = $this->pendingOpportunity();
        $this->actingAs($this->admin)->post(route('opportunities.approve', $opportunity))->assertSessionHas('success');
        $this->post(route('opportunities.approve', $opportunity))->assertSessionHas('error', 'This opportunity has already been executed.');

        $this->assertSame(TradeOpportunityStatus::Executed, $opportunity->fresh()->status);
        $this->assertDatabaseCount('paper_positions', 1);
        $this->assertDatabaseCount('trade_opportunity_events', 1);
        $this->assertEqualsWithDelta(4.9, PaperWallet::query()->sole()->available_balance_sol, 0.000001);
    }

    public function test_ignore_is_idempotent_and_ignored_opportunity_cannot_execute(): void
    {
        $opportunity = $this->pendingOpportunity();
        $this->actingAs($this->admin)->post(route('opportunities.ignore', $opportunity))->assertSessionHas('success', 'Opportunity ignored.');
        $this->post(route('opportunities.ignore', $opportunity))->assertSessionHas('success', 'Opportunity was already ignored.');
        $this->post(route('opportunities.approve', $opportunity))->assertSessionHas('error', 'This opportunity has been ignored and cannot be executed.');

        $this->assertSame(TradeOpportunityStatus::Ignored, $opportunity->fresh()->status);
        $this->assertDatabaseCount('trade_opportunity_events', 1);
        $this->assertDatabaseCount('paper_positions', 0);
        $this->assertEqualsWithDelta(5, PaperWallet::query()->sole()->available_balance_sol, 0.000001);
    }

    public function test_live_confirm_approval_is_blocked_and_records_safe_failure(): void
    {
        $opportunity = $this->pendingOpportunity();
        app(ApplicationSettingsService::class)->update(['trading.execution_mode' => 'live']);
        $this->actingAs($this->admin)->post(route('opportunities.approve', $opportunity))->assertSessionHas('error', 'Live execution is not enabled yet.');

        $opportunity->refresh();
        $this->assertSame(TradeOpportunityStatus::Failed, $opportunity->status);
        $this->assertSame(ExecutionMode::Live, $opportunity->execution_mode);
        $this->assertSame('live_execution_disabled', $opportunity->execution_data['reason']);
        $this->assertDatabaseCount('paper_positions', 0);
        $this->assertEqualsWithDelta(5, PaperWallet::query()->sole()->available_balance_sol, 0.000001);
    }

    public function test_auto_behavior_remains_automatic_and_opportunity_links_position(): void
    {
        $result = app(TradeOpportunityService::class)->qualify($this->candidate());

        $this->assertSame(TradeOpportunityStatus::Executed, $result['opportunity']->fresh()->status);
        $this->assertTrue($result['position']->is($result['opportunity']->fresh()->paperPosition));
        $this->assertDatabaseCount('paper_positions', 1);
    }

    private function pendingOpportunity(): TradeOpportunity
    {
        app(ApplicationSettingsService::class)->update(['trading.execution_mode' => 'paper', 'trading.entry_mode' => 'confirm']);
        $result = app(TradeOpportunityService::class)->qualify($this->candidate());
        $this->assertNull($result['position']);

        return $result['opportunity'];
    }

    /** @return array<string, mixed> */
    private function candidate(): array
    {
        return ['chain' => 'solana', 'address' => 'demo-token-address', 'symbol' => 'DEMO', 'name' => 'Demo Coin', 'entry_market_cap' => 10000, 'entry_price' => 0.001, 'entry_liquidity' => 3000, 'volume' => 7000, 'scanner' => 'test', 'security_data' => ['score' => 95], 'send_notification' => false];
    }
}
