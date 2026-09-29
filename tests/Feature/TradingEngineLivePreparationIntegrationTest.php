<?php

namespace Tests\Feature;

use App\Chain;
use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Enums\TradeOpportunityStatus;
use App\Jobs\ProjectTradingEngineEvent;
use App\Models\ConnectedWallet;
use App\Models\TradeOpportunity;
use App\Models\User;
use App\Models\UserTradingPreference;
use App\Services\ApplicationSettingsService;
use App\Services\EntryPolicy;
use App\Services\EthereumOpportunityRevalidationService;
use App\Services\EthereumService;
use App\Services\OpportunityActionService;
use App\Services\TradeExecutionManager;
use App\Services\TradingEngine\TradingEngineEvaluationConsumptionDecision;
use App\Services\TradingEngine\TradingEngineLiveDecisionIntegration;
use App\Services\TradingEngine\TradingEngineLivePreparationIntegration;
use App\Services\TradingEngine\TradingEngineOpportunityDecision;
use App\Services\TradingEngine\TradingEngineOpportunityProjector;
use App\Services\TradingEngine\TradingEnginePaperDecisionIntegration;
use App\Services\ZeroXSwapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TradingEngineLivePreparationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '0x2222222222222222222222222222222222222222';

    private const WALLET = '0x1111111111111111111111111111111111111111';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-29 23:00:00');
        config()->set([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'services.trading_engine.enabled' => true,
            'services.trading_engine.opportunity_export_enabled' => false,
            'services.trading_engine.opportunity_projection_enabled' => true,
            'services.trading_engine.evaluation_consumption_enabled' => true,
            'services.trading_engine.decision_boundary_enabled' => true,
            'services.trading_engine.paper_decision_integration_enabled' => false,
            'services.trading_engine.live_decision_integration_enabled' => true,
            'services.trading_engine.live_preparation_enabled' => true,
        ]);
        app(ApplicationSettingsService::class)->update([
            'risk.kill_switch' => false,
            'risk.max_trade_amount' => '0.1',
            'risk.max_slippage_percent' => 1,
        ]);
        Http::preventStrayRequests();
        Queue::fake();
        Notification::fake();
    }

    public function test_disabled_preparation_gate_preserves_the_read_only_live_decision_boundary(): void
    {
        config()->set('services.trading_engine.live_preparation_enabled', false);
        $opportunity = $this->opportunity();
        $this->mock(TradingEngineLiveDecisionIntegration::class)->shouldNotReceive('assess');

        $this->assertNull(app(TradingEngineLivePreparationIntegration::class)->attempt($opportunity));
        $this->assertNoExecutionSideEffects();
    }

    #[DataProvider('preparationPrerequisiteProvider')]
    public function test_every_preparation_prerequisite_fails_closed(string $flag): void
    {
        config()->set($flag, false);
        $opportunity = $this->opportunity();
        $this->wallet($opportunity->user);
        $this->mock(TradingEngineLiveDecisionIntegration::class)->shouldNotReceive('assess');

        $this->assertNull(app(TradingEngineLivePreparationIntegration::class)->attempt($opportunity));
        $this->assertNoExecutionSideEffects();
    }

    /** @return array<string, array{string}> */
    public static function preparationPrerequisiteProvider(): array
    {
        return [
            'engine' => ['services.trading_engine.enabled'],
            'projection' => ['services.trading_engine.opportunity_projection_enabled'],
            'consumption' => ['services.trading_engine.evaluation_consumption_enabled'],
            'decision' => ['services.trading_engine.decision_boundary_enabled'],
            'LIVE decision' => ['services.trading_engine.live_decision_integration_enabled'],
            'preparation' => ['services.trading_engine.live_preparation_enabled'],
        ];
    }

    public function test_verified_ethereum_auto_decision_prepares_once_and_stops_before_signing_or_submission(): void
    {
        $opportunity = $this->opportunity();
        $this->wallet($opportunity->user);
        $this->mockWouldEnter();
        $this->providers();

        $attempt = app(TradingEngineLivePreparationIntegration::class)->attempt($opportunity);

        $this->assertNotNull($attempt);
        $this->assertSame('prepared', $attempt->status);
        $this->assertSame('100000000000000000', $attempt->sell_amount_wei);
        $this->assertSame(100, $attempt->slippage_bps);
        $this->assertNull($attempt->transaction_hash);
        $this->assertNull($attempt->submitted_at);
        $this->assertNull($attempt->signing_requested_at);
        $this->assertNull($attempt->signing_armed_at);
        $this->assertSame('prepared', $opportunity->fresh()->execution_data['stage']);
        $this->assertSame('trading_engine_live_preparation', $opportunity->fresh()->execution_data['source']);
        $this->assertSame(TradeOpportunityStatus::Executing, $opportunity->fresh()->status);
        $this->assertDatabaseCount('ethereum_swap_attempts', 1);
        $this->actingAs($opportunity->user)
            ->get(route('opportunities.show', $opportunity))
            ->assertOk()
            ->assertSeeText('Ready for wallet confirmation.')
            ->assertSeeText('Confirm & Buy');
        $this->assertNoExecutionSideEffects(false);
    }

    public function test_duplicate_preparation_returns_the_same_attempt_without_a_second_quote(): void
    {
        $opportunity = $this->opportunity();
        $this->wallet($opportunity->user);
        $this->mockWouldEnter();
        $this->providers();
        $integration = app(TradingEngineLivePreparationIntegration::class);

        $first = $integration->attempt($opportunity);
        $second = $integration->attempt($opportunity->getKey());

        $this->assertNotNull($first);
        $this->assertSame($first->id, $second?->id);
        $this->assertDatabaseCount('ethereum_swap_attempts', 1);
        $this->assertNull($second?->transaction_hash);
        $this->assertNull($second?->submitted_at);
    }

    public function test_entry_policy_reaches_preparation_but_never_the_execution_manager(): void
    {
        $opportunity = $this->opportunity();
        $this->wallet($opportunity->user);
        $this->mockWouldEnter(3);
        $this->providers();
        $this->mock(TradeExecutionManager::class)->shouldNotReceive('execute', 'executePaper');

        $this->assertNull(app(EntryPolicy::class)->apply($opportunity));

        $attempt = $opportunity->fresh()->ethereumSwapAttempt;
        $this->assertSame('prepared', $attempt?->status);
        $this->assertNull($attempt?->transaction_hash);
        $this->assertNull($attempt?->submitted_at);
        $this->assertDatabaseCount('live_positions', 0);
    }

    public function test_expired_prepared_attempt_is_not_requoted_or_submitted(): void
    {
        $opportunity = $this->opportunity();
        $this->wallet($opportunity->user);
        $this->mockWouldEnter();
        $this->providers();
        $integration = app(TradingEngineLivePreparationIntegration::class);
        $attempt = $integration->attempt($opportunity);
        $attempt?->update(['expires_at' => now()->subSecond()]);

        $this->assertNull($integration->attempt($opportunity));
        $this->assertSame('prepared', $attempt?->fresh()->status);
        $this->assertNull($attempt?->fresh()->transaction_hash);
        $this->assertNull($attempt?->fresh()->submitted_at);
        $this->assertDatabaseCount('ethereum_swap_attempts', 1);
    }

    public function test_missing_or_foreign_wallet_fails_closed(): void
    {
        $missing = $this->opportunity();
        $this->mockWouldEnter(4);

        $this->assertNull(app(TradingEngineLivePreparationIntegration::class)->attempt($missing));

        $foreign = $this->opportunity();
        $this->wallet(User::factory()->create());

        $this->assertNull(app(TradingEngineLivePreparationIntegration::class)->attempt($foreign));
        $this->assertDatabaseCount('ethereum_swap_attempts', 0);
        $this->assertDatabaseCount('live_positions', 0);
    }

    #[DataProvider('nonEnteringDecisionProvider')]
    public function test_non_entering_decisions_never_prepare(string $decisionCode): void
    {
        $opportunity = $this->opportunity();
        $this->wallet($opportunity->user);
        $decision = $decisionCode === TradingEngineOpportunityDecision::WOULD_HOLD
            ? TradingEngineOpportunityDecision::wouldHold($this->eligibleConsumption(), 'CONFIRMATION_REQUIRED')
            : TradingEngineOpportunityDecision::wouldReject($this->eligibleConsumption(), 'EVALUATION_OUTCOME_FAILED');
        $this->mock(TradingEngineLiveDecisionIntegration::class)->shouldReceive('assess')->once()->andReturn($decision);

        $this->assertNull(app(TradingEngineLivePreparationIntegration::class)->attempt($opportunity));
        $this->assertNoExecutionSideEffects();
    }

    /** @return array<string, array{string}> */
    public static function nonEnteringDecisionProvider(): array
    {
        return [
            'WOULD_HOLD' => [TradingEngineOpportunityDecision::WOULD_HOLD],
            'WOULD_REJECT' => [TradingEngineOpportunityDecision::WOULD_REJECT],
        ];
    }

    public function test_kill_switch_disabled_user_and_expired_opportunity_fail_closed(): void
    {
        $killSwitch = $this->opportunity();
        $this->wallet($killSwitch->user);
        app(ApplicationSettingsService::class)->update(['risk.kill_switch' => true]);
        $this->mockWouldEnter(6);
        $this->assertNull(app(TradingEngineLivePreparationIntegration::class)->attempt($killSwitch));

        app(ApplicationSettingsService::class)->update(['risk.kill_switch' => false]);
        $disabled = $this->opportunity();
        $this->wallet($disabled->user, '0x'.str_repeat('3', 40));
        $disabled->user->tradingPreference()->update(['trading_enabled' => false]);
        $this->assertNull(app(TradingEngineLivePreparationIntegration::class)->attempt($disabled));

        $expired = $this->opportunity(['qualified_at' => now()->subHour()]);
        $this->wallet($expired->user, '0x'.str_repeat('4', 40));
        $this->assertNull(app(TradingEngineLivePreparationIntegration::class)->attempt($expired));
        $this->assertDatabaseCount('ethereum_swap_attempts', 0);
    }

    public function test_quote_failure_releases_the_single_reservation_without_submission(): void
    {
        $opportunity = $this->opportunity();
        $this->wallet($opportunity->user);
        $this->mockWouldEnter();
        $this->revalidation();
        $this->mock(EthereumService::class)->shouldReceive('getBalanceWei')->once()->andReturn('1000000000000000000');
        $this->mock(ZeroXSwapService::class)->shouldReceive('quote')->once()->andThrow(new RuntimeException('provider secret'));

        $this->assertNull(app(TradingEngineLivePreparationIntegration::class)->attempt($opportunity));

        $attempt = $opportunity->fresh()->ethereumSwapAttempt;
        $this->assertNotNull($attempt);
        $this->assertSame('released', $attempt->status);
        $this->assertNull($attempt->transaction_hash);
        $this->assertNull($attempt->submitted_at);
        $this->assertSame(TradeOpportunityStatus::Qualified, $opportunity->fresh()->status);
        $this->assertDatabaseCount('ethereum_swap_attempts', 1);
        $this->assertDatabaseCount('live_positions', 0);
    }

    public function test_paper_and_solana_opportunities_are_unsupported_without_attempts_or_provider_calls(): void
    {
        $paper = $this->opportunity(['execution_mode' => ExecutionMode::Paper]);
        $solana = $this->opportunity([
            'chain' => Chain::Solana,
            'address' => 'So11111111111111111111111111111111111111112',
        ]);
        $this->mock(TradingEngineLiveDecisionIntegration::class)->shouldNotReceive('assess');

        $this->assertNull(app(TradingEngineLivePreparationIntegration::class)->attempt($paper));
        $this->assertNull(app(TradingEngineLivePreparationIntegration::class)->attempt($solana));
        $this->assertNoExecutionSideEffects();
    }

    public function test_projection_hook_delegates_each_projected_event_once_without_execution_logic(): void
    {
        $projector = $this->mock(TradingEngineOpportunityProjector::class);
        $paper = $this->mock(TradingEnginePaperDecisionIntegration::class);
        $live = $this->mock(TradingEngineLiveDecisionIntegration::class);
        $preparation = $this->mock(TradingEngineLivePreparationIntegration::class);
        $projector->shouldReceive('project')->once()->with('event-main')->andReturn(['dependent_event_ids' => ['event-dependent']]);
        $projector->shouldReceive('project')->once()->with('event-dependent')->andReturn(['dependent_event_ids' => []]);
        $paper->shouldReceive('attemptForProjectedEvaluation')->once()->with('event-main');
        $paper->shouldReceive('attemptForProjectedEvaluation')->once()->with('event-dependent');
        $live->shouldReceive('assessForProjectedEvaluation')->once()->with('event-main');
        $live->shouldReceive('assessForProjectedEvaluation')->once()->with('event-dependent');
        $preparation->shouldReceive('attemptForProjectedEvaluation')->once()->with('event-main');
        $preparation->shouldReceive('attemptForProjectedEvaluation')->once()->with('event-dependent');

        (new ProjectTradingEngineEvent('event-main'))->handle($projector, $paper, $live, $preparation);

        $this->assertNoExecutionSideEffects();
    }

    public function test_existing_manual_confirm_reservation_remains_explicit_and_unsubmitted(): void
    {
        $user = User::factory()->create();
        UserTradingPreference::factory()->create([
            'user_id' => $user->id,
            'execution_mode' => ExecutionMode::Live,
            'entry_mode' => EntryMode::Confirm,
            'trading_enabled' => true,
        ]);
        $this->wallet($user);
        $opportunity = TradeOpportunity::factory()->create([
            'user_id' => $user->id,
            'chain' => Chain::Ethereum,
            'address' => self::TOKEN,
            'scanner' => 'new-token',
            'status' => TradeOpportunityStatus::PendingConfirmation,
            'execution_mode' => ExecutionMode::Live,
            'entry_mode' => EntryMode::Confirm,
            'qualified_at' => now(),
        ]);
        $this->mock(TradingEngineLiveDecisionIntegration::class)
            ->shouldReceive('assess')
            ->once()
            ->andReturn(TradingEngineOpportunityDecision::wouldHold(
                $this->eligibleConsumption(),
                'CONFIRMATION_REQUIRED',
            ));

        $attempt = app(OpportunityActionService::class)->approve($opportunity, $user, [
            'sell_amount_wei' => '1000000000000000',
            'slippage_bps' => 100,
        ]);

        $this->assertSame('reserved', $attempt->status);
        $this->assertNull($attempt->transaction_payload);
        $this->assertNull($attempt->transaction_hash);
        $this->assertNull($attempt->submitted_at);
        $this->assertDatabaseCount('ethereum_swap_attempts', 1);
        $this->assertDatabaseCount('live_positions', 0);
    }

    /** @param array<string, mixed> $overrides */
    private function opportunity(array $overrides = []): TradeOpportunity
    {
        $user = User::factory()->create();
        UserTradingPreference::factory()->create([
            'user_id' => $user->id,
            'execution_mode' => ExecutionMode::Live,
            'entry_mode' => EntryMode::Auto,
            'trading_enabled' => true,
        ]);

        return TradeOpportunity::factory()->create([
            'user_id' => $user->id,
            'chain' => Chain::Ethereum,
            'address' => self::TOKEN,
            'scanner' => 'new-token',
            'status' => TradeOpportunityStatus::Qualified,
            'execution_mode' => ExecutionMode::Live,
            'entry_mode' => EntryMode::Auto,
            'qualified_at' => now(),
            'execution_data' => ['unchanged' => true],
            ...$overrides,
        ]);
    }

    private function wallet(User $user, string $address = self::WALLET): ConnectedWallet
    {
        return ConnectedWallet::query()->create([
            'user_id' => $user->id,
            'chain' => Chain::Ethereum,
            'address' => $address,
            'address_hash' => ConnectedWallet::addressHash(Chain::Ethereum, $address),
            'verified_at' => now(),
        ]);
    }

    private function mockWouldEnter(int $calls = 2): void
    {
        $this->mock(TradingEngineLiveDecisionIntegration::class)
            ->shouldReceive('assess')
            ->times($calls)
            ->andReturn(TradingEngineOpportunityDecision::wouldEnter(
                $this->eligibleConsumption(),
                'AUTO_ENTRY_CONFIGURED',
            ));
    }

    private function eligibleConsumption(): TradingEngineEvaluationConsumptionDecision
    {
        return TradingEngineEvaluationConsumptionDecision::eligible(
            '01KC0000000000000000012345',
            '01KC0000000000000000067890',
        );
    }

    private function providers(): void
    {
        $this->revalidation();
        $this->mock(EthereumService::class)
            ->shouldReceive('getBalanceWei')
            ->once()
            ->with(self::WALLET)
            ->andReturn('1000000000000000000');
        $this->mock(ZeroXSwapService::class)
            ->shouldReceive('quote')
            ->once()
            ->with(self::WALLET, self::TOKEN, '100000000000000000', 100)
            ->andReturn([
                'quote_id' => 'engine-preparation-quote',
                'network_fee_wei' => '21000',
                'transaction' => [
                    'from' => self::WALLET,
                    'to' => '0x'.str_repeat('4', 40),
                    'value' => '100000000000000000',
                    'data' => '0x1234',
                    'chainId' => '1',
                    'gas' => '21000',
                    'gasPrice' => '1',
                ],
            ]);
    }

    private function revalidation(): void
    {
        $this->mock(EthereumOpportunityRevalidationService::class)
            ->shouldReceive('check')
            ->once()
            ->andReturn([
                'passed' => true,
                'failure_class' => null,
                'reason' => 'qualified',
                'scanner' => 'new-token',
                'checked_at' => now()->toIso8601String(),
            ]);
    }

    private function assertNoExecutionSideEffects(bool $expectNoAttempt = true): void
    {
        if ($expectNoAttempt) {
            $this->assertDatabaseCount('ethereum_swap_attempts', 0);
        }
        $this->assertDatabaseCount('solana_swap_attempts', 0);
        $this->assertDatabaseCount('paper_positions', 0);
        $this->assertDatabaseCount('live_positions', 0);
        Queue::assertNothingPushed();
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }
}
