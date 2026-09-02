<?php

namespace Tests\Feature;

use App\Enums\TradeOpportunityStatus;
use App\Models\PaperPosition;
use App\Models\PaperWallet;
use App\Models\TradeOpportunity;
use App\Services\ApplicationSettingsService;
use App\Services\TelegramService;
use App\Services\TradeExecutionManager;
use App\Services\TradeOpportunityService;
use RuntimeException;
use Tests\Concerns\RefreshesPaperTradingDatabase;
use Tests\TestCase;

class TradeExecutionArchitectureTest extends TestCase
{
    use RefreshesPaperTradingDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshPaperTradingDatabase();
        PaperWallet::query()->create(['name' => 'default', 'starting_balance_sol' => 5, 'available_balance_sol' => 5, 'invested_balance_sol' => 0, 'realized_pnl_sol' => 0]);
        $this->mock(TelegramService::class)->shouldReceive('send')->zeroOrMoreTimes();
    }

    public function test_signal_records_opportunity_without_creating_position(): void
    {
        app(ApplicationSettingsService::class)->update(['trading.entry_mode' => 'signal']);
        $result = app(TradeOpportunityService::class)->qualify($this->opportunity());

        $this->assertNull($result['position']);
        $this->assertSame(TradeOpportunityStatus::Qualified, $result['opportunity']->status);
        $this->assertDatabaseCount('paper_positions', 0);
    }

    public function test_confirm_records_pending_opportunity_without_execution(): void
    {
        app(ApplicationSettingsService::class)->update(['trading.entry_mode' => 'confirm']);
        $result = app(TradeOpportunityService::class)->qualify($this->opportunity());

        $this->assertNull($result['position']);
        $this->assertSame(TradeOpportunityStatus::PendingConfirmation, $result['opportunity']->fresh()->status);
        $this->assertDatabaseCount('paper_positions', 0);
    }

    public function test_paper_auto_executes_once_through_existing_entry_service(): void
    {
        app(ApplicationSettingsService::class)->update(['trading.execution_mode' => 'paper', 'trading.entry_mode' => 'auto']);
        $result = app(TradeOpportunityService::class)->qualify($this->opportunity());

        $this->assertInstanceOf(PaperPosition::class, $result['position']);
        $this->assertSame(TradeOpportunityStatus::Executed, $result['opportunity']->fresh()->status);
        $this->assertNotNull($result['position']->strategy_snapshot);
        $this->assertEqualsWithDelta(4.9, PaperWallet::query()->sole()->available_balance_sol, 0.000001);
    }

    public function test_live_auto_is_blocked_server_side(): void
    {
        app(ApplicationSettingsService::class)->update(['trading.execution_mode' => 'live', 'trading.entry_mode' => 'auto']);

        try {
            app(TradeOpportunityService::class)->qualify($this->opportunity());
            $this->fail('Live execution did not refuse the trade.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Live execution is not enabled yet.', $exception->getMessage());
        }

        $opportunity = TradeOpportunity::query()->sole();
        $this->assertSame(TradeOpportunityStatus::Failed, $opportunity->status);
        $this->assertSame('live_execution_disabled', $opportunity->execution_data['reason']);
        $this->assertDatabaseCount('paper_positions', 0);
    }

    public function test_kill_switch_records_an_ignored_opportunity_without_execution(): void
    {
        app(ApplicationSettingsService::class)->update(['risk.kill_switch' => true]);

        $result = app(TradeOpportunityService::class)->qualify($this->opportunity());

        $this->assertNull($result['position']);
        $this->assertSame(TradeOpportunityStatus::Ignored, $result['opportunity']->fresh()->status);
        $this->assertDatabaseCount('paper_positions', 0);
    }

    public function test_executed_opportunity_cannot_execute_twice(): void
    {
        $result = app(TradeOpportunityService::class)->qualify($this->opportunity());
        $position = $result['position'];
        $again = app(TradeExecutionManager::class)->execute($result['opportunity']->fresh());

        $this->assertTrue($position->is($again));
        $this->assertDatabaseCount('paper_positions', 1);
        $this->assertEqualsWithDelta(4.9, PaperWallet::query()->sole()->available_balance_sol, 0.000001);
    }

    /** @return array<string, mixed> */
    private function opportunity(): array
    {
        return ['chain' => 'solana', 'address' => 'token-address', 'symbol' => 'TEST', 'name' => 'Test', 'entry_market_cap' => 10_000, 'entry_price' => 0.001, 'entry_liquidity' => 2_000, 'scanner' => 'test', 'send_notification' => false];
    }
}
