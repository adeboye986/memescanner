<?php

namespace Tests\Feature;

use App\Chain;
use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Enums\TradeOpportunityStatus;
use App\Jobs\ProjectTradingEngineEvent;
use App\Models\TradeOpportunity;
use App\Models\TradingEngineEvent;
use App\Models\TradingEngineOpportunityEvaluation;
use App\Models\TradingEngineOpportunityLink;
use App\Models\User;
use App\Models\UserTradingPreference;
use App\Services\ApplicationSettingsService;
use App\Services\EntryPolicy;
use App\Services\EthereumOpportunityReservationService;
use App\Services\OpportunityActionService;
use App\Services\TradeExecutionManager;
use App\Services\Trading\LiveTradeExecutor;
use App\Services\TradingEngine\TradingEngineEvaluationConsumptionPolicy;
use App\Services\TradingEngine\TradingEngineLiveDecisionIntegration;
use App\Services\TradingEngine\TradingEngineOpportunityDecision;
use App\Services\TradingEngine\TradingEngineOpportunityDecisionPolicy;
use App\Services\TradingEngine\TradingEngineOpportunityProjector;
use App\Services\TradingEngine\TradingEnginePaperDecisionIntegration;
use App\Services\UserTelegramNotificationService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TradingEngineLiveDecisionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const TRACEPARENT = '00-0123456789abcdef0123456789abcdef-0123456789abcdef-01';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-29 22:00:00');
        config()->set([
            'services.trading_engine.enabled' => true,
            'services.trading_engine.opportunity_export_enabled' => false,
            'services.trading_engine.opportunity_projection_enabled' => true,
            'services.trading_engine.evaluation_consumption_enabled' => true,
            'services.trading_engine.decision_boundary_enabled' => true,
            'services.trading_engine.paper_decision_integration_enabled' => false,
            'services.trading_engine.live_decision_integration_enabled' => true,
        ]);
        Queue::fake();
        Notification::fake();
        Http::preventStrayRequests();
        $this->mock(UserTelegramNotificationService::class)->shouldNotReceive('send');
    }

    public function test_disabled_flag_preserves_existing_live_auto_refusal(): void
    {
        config()->set('services.trading_engine.live_decision_integration_enabled', false);
        $opportunity = $this->opportunity(1);

        try {
            app(EntryPolicy::class)->apply($opportunity);
            $this->fail('The legacy LIVE executor did not refuse execution.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Live execution is not enabled yet.', $exception->getMessage());
        }

        $this->assertSame(TradeOpportunityStatus::Failed, $opportunity->fresh()->status);
        $this->assertSame('live_execution_disabled', $opportunity->fresh()->execution_data['reason']);
        $this->assertNoTradingSideEffects();
    }

    public function test_enabled_eligible_live_auto_reaches_would_enter_and_stops(): void
    {
        $chain = $this->chain(2);
        $this->mock(TradeExecutionManager::class)->shouldNotReceive('execute', 'executePaper');
        $this->mock(LiveTradeExecutor::class)->shouldNotReceive('execute');

        $decision = $this->integration()->assess($chain['opportunity']);
        $position = app(EntryPolicy::class)->apply($chain['opportunity']);

        $this->assertDecision($decision, TradingEngineOpportunityDecision::WOULD_ENTER, 'AUTO_ENTRY_CONFIGURED');
        $this->assertNull($position);
        $this->assertSame(TradeOpportunityStatus::Qualified, $chain['opportunity']->fresh()->status);
        $this->assertSame(['unchanged' => true], $chain['opportunity']->fresh()->execution_data);
        $this->assertNoTradingSideEffects();
    }

    #[DataProvider('prerequisiteFlagProvider')]
    public function test_every_required_upstream_gate_fails_closed(string $flag, string $reasonCode): void
    {
        $chain = $this->chain(3 + crc32($flag) % 1000);
        config()->set($flag, false);

        $decision = $this->integration()->assess($chain['opportunity']);

        $this->assertDecision($decision, TradingEngineOpportunityDecision::WOULD_REJECT, $reasonCode);
        $this->assertSame(TradeOpportunityStatus::Qualified, $chain['opportunity']->fresh()->status);
        $this->assertNoTradingSideEffects();
    }

    /** @return array<string, array{string, string}> */
    public static function prerequisiteFlagProvider(): array
    {
        return [
            'engine integration' => ['services.trading_engine.enabled', 'ENGINE_INTEGRATION_DISABLED'],
            'projection' => ['services.trading_engine.opportunity_projection_enabled', 'OPPORTUNITY_PROJECTION_DISABLED'],
            'evaluation consumption' => ['services.trading_engine.evaluation_consumption_enabled', 'EVALUATION_CONSUMPTION_DISABLED'],
            'decision boundary' => ['services.trading_engine.decision_boundary_enabled', 'DECISION_BOUNDARY_DISABLED'],
            'LIVE integration' => ['services.trading_engine.live_decision_integration_enabled', 'LIVE_DECISION_INTEGRATION_DISABLED'],
        ];
    }

    #[DataProvider('holdingEntryModeProvider')]
    public function test_confirm_and_signal_hold_without_execution(
        EntryMode $entryMode,
        TradeOpportunityStatus $expectedStatus,
        string $reasonCode,
    ): void {
        $chain = $this->chain(10 + ($entryMode === EntryMode::Confirm ? 1 : 2), [
            'opportunity' => ['entry_mode' => $entryMode],
        ]);
        $this->mock(TradeExecutionManager::class)->shouldNotReceive('execute', 'executePaper');

        $decision = $this->integration()->assess($chain['opportunity']);
        $position = app(EntryPolicy::class)->apply($chain['opportunity']);

        $this->assertDecision($decision, TradingEngineOpportunityDecision::WOULD_HOLD, $reasonCode);
        $this->assertNull($position);
        $this->assertSame($expectedStatus, $chain['opportunity']->fresh()->status);
        $this->assertNoTradingSideEffects();
    }

    /** @return array<string, array{EntryMode, TradeOpportunityStatus, string}> */
    public static function holdingEntryModeProvider(): array
    {
        return [
            'confirm' => [EntryMode::Confirm, TradeOpportunityStatus::PendingConfirmation, 'CONFIRMATION_REQUIRED'],
            'signal' => [EntryMode::Signal, TradeOpportunityStatus::Qualified, 'SIGNAL_ONLY'],
        ];
    }

    public function test_kill_switch_and_disabled_user_reject_without_execution(): void
    {
        $killSwitch = $this->chain(20);
        app(ApplicationSettingsService::class)->update(['risk.kill_switch' => true]);
        $killDecision = $this->integration()->assess($killSwitch['opportunity']);

        app(ApplicationSettingsService::class)->update(['risk.kill_switch' => false]);
        $disabledUser = $this->chain(21);
        UserTradingPreference::factory()->create([
            'user_id' => $disabledUser['opportunity']->user_id,
            'execution_mode' => ExecutionMode::Live,
            'entry_mode' => EntryMode::Auto,
            'trading_enabled' => false,
        ]);
        $userDecision = $this->integration()->assess($disabledUser['opportunity']);

        $this->assertDecision($killDecision, TradingEngineOpportunityDecision::WOULD_REJECT, 'KILL_SWITCH_ACTIVE');
        $this->assertDecision($userDecision, TradingEngineOpportunityDecision::WOULD_REJECT, 'USER_TRADING_DISABLED');
        $this->assertSame(TradeOpportunityStatus::Qualified, $killSwitch['opportunity']->fresh()->status);
        $this->assertSame(TradeOpportunityStatus::Qualified, $disabledUser['opportunity']->fresh()->status);
        $this->assertNoTradingSideEffects();
    }

    #[DataProvider('nonPassingEvaluationProvider')]
    public function test_non_passing_evaluations_reject_without_execution(string $outcome, string $expectedReason): void
    {
        $chain = $this->chain($outcome === 'failed' ? 30 : 31, [
            'outcome' => $outcome,
            'reason_codes' => ['ENGINE_REASON'],
        ]);

        $decision = $this->integration()->assess($chain['opportunity']);

        $this->assertDecision($decision, TradingEngineOpportunityDecision::WOULD_REJECT, $expectedReason);
        $this->assertNoTradingSideEffects();
    }

    /** @return array<string, array{string, string}> */
    public static function nonPassingEvaluationProvider(): array
    {
        return [
            'failed' => ['failed', 'EVALUATION_OUTCOME_FAILED'],
            'indeterminate' => ['indeterminate', 'EVALUATION_OUTCOME_INDETERMINATE'],
        ];
    }

    public function test_missing_projection_and_integrity_mismatch_fail_closed(): void
    {
        $missing = $this->opportunity(40);
        $mismatch = $this->chain(41, ['source_request_sha256' => str_repeat('0', 64)]);

        $missingDecision = $this->integration()->assess($missing);
        $mismatchDecision = $this->integration()->assess($mismatch['opportunity']);

        $this->assertDecision($missingDecision, TradingEngineOpportunityDecision::WOULD_REJECT, 'OPPORTUNITY_LINK_MISSING');
        $this->assertDecision($mismatchDecision, TradingEngineOpportunityDecision::WOULD_REJECT, 'SOURCE_REQUEST_HASH_MISMATCH');
        $this->assertNoTradingSideEffects();
    }

    public function test_unexpected_policy_exception_fails_closed_without_exposing_details(): void
    {
        $chain = $this->chain(50);
        $policy = $this->mock(TradingEngineOpportunityDecisionPolicy::class);
        $policy->shouldReceive('assess')->once()->andThrow(new RuntimeException('secret diagnostic detail'));

        $decision = app(TradingEngineLiveDecisionIntegration::class)->assess($chain['opportunity']);

        $this->assertDecision($decision, TradingEngineOpportunityDecision::WOULD_REJECT, 'LIVE_DECISION_UNAVAILABLE');
        $this->assertNotContains('secret diagnostic detail', $decision->reasonCodes);
        $this->assertNoTradingSideEffects();
    }

    public function test_repeated_invocation_is_deterministic_and_read_only(): void
    {
        $chain = $this->chain(60);
        $before = $chain['opportunity']->fresh()->getRawOriginal();

        $first = $this->integration()->assess($chain['opportunity']);
        $second = $this->integration()->assess($chain['opportunity']->getKey());

        $this->assertEquals($first, $second);
        $this->assertSame($before, $chain['opportunity']->fresh()->getRawOriginal());
        $this->assertDatabaseCount('trading_engine_opportunity_links', 1);
        $this->assertDatabaseCount('trading_engine_opportunity_evaluations', 1);
        $this->assertNoTradingSideEffects();
    }

    #[DataProvider('terminalStatusProvider')]
    public function test_terminal_or_in_progress_opportunities_are_never_reconsidered(TradeOpportunityStatus $status): void
    {
        $chain = $this->chain(65 + crc32($status->value) % 1000, [
            'opportunity' => ['status' => $status],
        ]);

        $decision = $this->integration()->assess($chain['opportunity']);

        $this->assertDecision(
            $decision,
            TradingEngineOpportunityDecision::WOULD_REJECT,
            'LIVE_OPPORTUNITY_STATE_INELIGIBLE',
        );
        $this->assertSame($status, $chain['opportunity']->fresh()->status);
        $this->assertNoTradingSideEffects();
    }

    /** @return array<string, array{TradeOpportunityStatus}> */
    public static function terminalStatusProvider(): array
    {
        return [
            'executing' => [TradeOpportunityStatus::Executing],
            'executed' => [TradeOpportunityStatus::Executed],
            'ignored' => [TradeOpportunityStatus::Ignored],
            'expired' => [TradeOpportunityStatus::Expired],
            'failed' => [TradeOpportunityStatus::Failed],
        ];
    }

    public function test_reversed_projection_delivery_converges_to_the_same_safe_decision(): void
    {
        $projection = $this->pendingProjection(70);
        $projector = app(TradingEngineOpportunityProjector::class);
        $paper = app(TradingEnginePaperDecisionIntegration::class);
        $integration = $this->integration();

        (new ProjectTradingEngineEvent($projection['evaluated']->event_id))->handle($projector, $paper, $integration);
        $this->assertDatabaseCount('trading_engine_opportunity_links', 0);
        $this->assertDatabaseCount('trading_engine_opportunity_evaluations', 0);

        (new ProjectTradingEngineEvent($projection['recorded']->event_id))->handle($projector, $paper, $integration);
        $decision = $integration->assess($projection['opportunity']);

        $this->assertDecision($decision, TradingEngineOpportunityDecision::WOULD_ENTER, 'AUTO_ENTRY_CONFIGURED');
        $this->assertDatabaseCount('trading_engine_opportunity_links', 1);
        $this->assertDatabaseCount('trading_engine_opportunity_evaluations', 1);
        $this->assertSame(TradeOpportunityStatus::Qualified, $projection['opportunity']->fresh()->status);
        $this->assertNoTradingSideEffects();
    }

    public function test_paper_opportunity_cannot_cross_live_boundary(): void
    {
        $chain = $this->chain(80, [
            'opportunity' => ['execution_mode' => ExecutionMode::Paper],
        ]);

        $decision = $this->integration()->assess($chain['opportunity']);

        $this->assertDecision($decision, TradingEngineOpportunityDecision::WOULD_REJECT, 'LIVE_EXECUTION_MODE_REQUIRED');
        $this->assertNoTradingSideEffects();
    }

    #[DataProvider('liveChainProvider')]
    public function test_live_approval_stops_before_chain_specific_reservation_or_execution(Chain $chain): void
    {
        $evaluation = $this->chain(90 + ($chain === Chain::Ethereum ? 1 : 2), [
            'opportunity' => [
                'chain' => $chain,
                'address' => $chain === Chain::Ethereum
                    ? '0x1111111111111111111111111111111111111111'
                    : 'So11111111111111111111111111111111111111112',
                'entry_mode' => EntryMode::Confirm,
                'status' => TradeOpportunityStatus::PendingConfirmation,
            ],
        ]);
        $opportunity = $evaluation['opportunity'];
        $user = $opportunity->user;
        UserTradingPreference::factory()->create([
            'user_id' => $user->getKey(),
            'execution_mode' => ExecutionMode::Live,
            'entry_mode' => EntryMode::Confirm,
            'trading_enabled' => true,
        ]);
        $this->mock(EthereumOpportunityReservationService::class)->shouldNotReceive('reserve');
        $this->mock(TradeExecutionManager::class)->shouldNotReceive('execute', 'executePaper');
        $this->mock(LiveTradeExecutor::class)->shouldNotReceive('execute');

        try {
            app(OpportunityActionService::class)->approve($opportunity, $user, [
                'sell_amount_wei' => '1000000000000000',
                'slippage_bps' => 100,
            ]);
            $this->fail('The informational LIVE boundary allowed execution to continue.');
        } catch (DomainException $exception) {
            $this->assertSame(
                'The informational LIVE decision cannot authorize execution. No execution was started.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(TradeOpportunityStatus::PendingConfirmation, $opportunity->fresh()->status);
        $this->assertSame(['unchanged' => true], $opportunity->fresh()->execution_data);
        $this->assertNoTradingSideEffects();
    }

    /** @return array<string, array{Chain}> */
    public static function liveChainProvider(): array
    {
        return [
            'Solana' => [Chain::Solana],
            'Ethereum' => [Chain::Ethereum],
        ];
    }

    public function test_entry_policy_fails_closed_if_integration_itself_throws(): void
    {
        $integration = $this->mock(TradingEngineLiveDecisionIntegration::class);
        $integration->shouldReceive('assess')->once()->andThrow(new RuntimeException('secret diagnostic detail'));
        $this->mock(TradeExecutionManager::class)->shouldNotReceive('execute', 'executePaper');
        $opportunity = $this->opportunity(100);

        $this->assertNull(app(EntryPolicy::class)->apply($opportunity));
        $this->assertSame(TradeOpportunityStatus::Qualified, $opportunity->fresh()->status);
        $this->assertNoTradingSideEffects();
    }

    private function integration(): TradingEngineLiveDecisionIntegration
    {
        return app(TradingEngineLiveDecisionIntegration::class);
    }

    private function assertDecision(
        TradingEngineOpportunityDecision $decision,
        string $decisionCode,
        string $reasonCode,
    ): void {
        $this->assertSame($decisionCode, $decision->decisionCode);
        $this->assertSame([$reasonCode], $decision->reasonCodes);
    }

    private function assertNoTradingSideEffects(): void
    {
        $this->assertDatabaseCount('paper_wallets', 0);
        $this->assertDatabaseCount('paper_positions', 0);
        $this->assertDatabaseCount('live_positions', 0);
        $this->assertDatabaseCount('connected_wallets', 0);
        $this->assertDatabaseCount('solana_swap_attempts', 0);
        $this->assertDatabaseCount('ethereum_swap_attempts', 0);
        $this->assertDatabaseCount('trade_opportunity_events', 0);
        Queue::assertNothingPushed();
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    /** @param array<string, mixed> $overrides */
    private function opportunity(int $sequence, array $overrides = []): TradeOpportunity
    {
        return TradeOpportunity::factory()->for(User::factory())->create([
            'chain' => Chain::Solana,
            'discovery_key' => hash('sha256', 'live-decision-discovery-'.$sequence),
            'address' => 'So11111111111111111111111111111111111111112',
            'symbol' => 'TEST',
            'name' => 'Test Token',
            'scanner' => 'new-token',
            'status' => TradeOpportunityStatus::Qualified,
            'execution_mode' => ExecutionMode::Live,
            'entry_mode' => EntryMode::Auto,
            'price' => 0.001,
            'market_cap' => 12000,
            'liquidity' => 1000,
            'volume' => 5000,
            'qualification_data' => ['send_notification' => false],
            'security_data' => ['score' => 95],
            'execution_data' => ['unchanged' => true],
            'qualified_at' => now(),
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{opportunity: TradeOpportunity, recorded: TradingEngineEvent, link: TradingEngineOpportunityLink, evaluation_event: TradingEngineEvent, evaluation: TradingEngineOpportunityEvaluation}
     */
    private function chain(int $sequence, array $options = []): array
    {
        $opportunity = $this->opportunity($sequence, $options['opportunity'] ?? []);
        $eventData = $this->eventData($opportunity, $sequence, $options);
        $recorded = $this->event($eventData['recorded']);
        $link = TradingEngineOpportunityLink::query()->create([
            'trade_opportunity_id' => $opportunity->getKey(),
            'engine_opportunity_id' => $eventData['engine_opportunity_id'],
            'recorded_event_id' => $recorded->event_id,
            'user_id' => $opportunity->user_id,
            'discovery_key' => $opportunity->discovery_key,
            'scanner' => $opportunity->scanner,
            'network_id' => $this->networkId($opportunity->chain),
            'asset_address' => $opportunity->address,
            'recorded_at' => $recorded->occurred_at,
            'linked_at' => now(),
        ]);
        $evaluationEvent = $this->event($eventData['evaluated']);
        $evaluation = TradingEngineOpportunityEvaluation::query()->create([
            'opportunity_link_id' => $link->getKey(),
            'trade_opportunity_id' => $opportunity->getKey(),
            'evaluation_id' => $eventData['evaluation_id'],
            'engine_opportunity_id' => $eventData['engine_opportunity_id'],
            'evaluation_event_id' => $evaluationEvent->event_id,
            'recorded_event_id' => $recorded->event_id,
            ...$eventData['evaluation_record'],
            'correlation_id' => $evaluationEvent->correlation_id,
            'traceparent' => $evaluationEvent->traceparent,
            'evaluated_at' => $evaluationEvent->occurred_at,
            'event_received_at' => $evaluationEvent->received_at,
            'projected_at' => now(),
        ]);

        return compact('opportunity', 'recorded', 'link', 'evaluation') + [
            'evaluation_event' => $evaluationEvent,
        ];
    }

    /**
     * @return array{opportunity: TradeOpportunity, recorded: TradingEngineEvent, evaluated: TradingEngineEvent}
     */
    private function pendingProjection(int $sequence): array
    {
        $opportunity = $this->opportunity($sequence);
        $eventData = $this->eventData($opportunity, $sequence, []);
        $recorded = $this->event($eventData['recorded'], TradingEngineEvent::STATUS_STORED);
        $evaluated = $this->event($eventData['evaluated'], TradingEngineEvent::STATUS_STORED);

        return compact('opportunity', 'recorded', 'evaluated');
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{engine_opportunity_id: string, evaluation_id: string, recorded: array<string, mixed>, evaluated: array<string, mixed>, evaluation_record: array<string, mixed>}
     */
    private function eventData(TradeOpportunity $opportunity, int $sequence, array $options): array
    {
        $engineOpportunityId = $this->engineId($sequence * 10 + 1);
        $recordedEventId = $this->engineId($sequence * 10 + 2);
        $evaluationEventId = $this->engineId($sequence * 10 + 3);
        $evaluationId = $this->engineId($sequence * 10 + 4);
        $operationId = $this->engineId($sequence * 10 + 5);
        $body = [
            'schema_version' => 1,
            'source' => [
                'system' => 'meme-scanner-laravel',
                'opportunity_id' => (string) $opportunity->getKey(),
                'discovery_key' => $opportunity->discovery_key,
                'scanner' => $opportunity->scanner,
            ],
            'subject' => ['control_plane_user_id' => (string) $opportunity->user_id],
            'network' => ['id' => $this->networkId($opportunity->chain)],
            'asset' => ['address' => $opportunity->address, 'symbol' => $opportunity->symbol],
            'market_snapshot' => [
                'market_cap_usd' => ['value' => '12000', 'provider' => 'birdeye'],
                'liquidity_usd' => ['value' => '1000', 'provider' => 'birdeye'],
            ],
            'qualification' => [
                'qualified_at' => '2026-09-29T22:00:00.000Z',
                'discovery_market_cap_usd' => '10000',
                'move_since_discovery_percent' => '20',
                'classification' => 'strong',
            ],
            'security' => ['status' => 'passed', 'provider' => 'goplus', 'passed' => true],
        ];
        $sourceRequestSha256 = $options['source_request_sha256'] ?? $this->canonicalHash($body);
        $evaluationInputSha256 = $this->canonicalHash([
            'policy_definition_sha256' => TradingEngineEvaluationConsumptionPolicy::APPROVED_POLICY_SHA256,
            'source_request_sha256' => $sourceRequestSha256,
        ]);
        $outcome = $options['outcome'] ?? 'passed';
        $reasonCodes = $options['reason_codes'] ?? [];
        $advisoryCodes = [];
        $evidence = [
            'profile' => $opportunity->chain === Chain::Ethereum ? 'ethereum:new-token' : 'solana:new-token',
            'facts' => [
                'market_cap_usd' => '12000',
                'liquidity_usd' => '1000',
                'volume_5m_usd' => null,
                'move_since_discovery_percent' => '20',
                'classification' => 'strong',
                'pair_address' => null,
                'pair_available' => null,
                'requested_token_is_base' => null,
                'security_status' => 'passed',
                'security_provider' => 'goplus',
                'security_passed' => true,
            ],
            'checks' => [
                ['check' => 'market_cap', 'status' => 'passed'],
                ['check' => 'liquidity', 'status' => 'passed'],
                ['check' => 'movement', 'status' => 'passed'],
                ['check' => 'classification', 'status' => 'passed'],
                ['check' => 'security', 'status' => 'passed'],
            ],
        ];
        $evaluationRecord = [
            'policy_key' => TradingEngineEvaluationConsumptionPolicy::APPROVED_POLICY_KEY,
            'policy_version' => TradingEngineEvaluationConsumptionPolicy::APPROVED_POLICY_VERSION,
            'algorithm_key' => TradingEngineEvaluationConsumptionPolicy::APPROVED_ALGORITHM_KEY,
            'algorithm_version' => TradingEngineEvaluationConsumptionPolicy::APPROVED_ALGORITHM_VERSION,
            'policy_definition_sha256' => TradingEngineEvaluationConsumptionPolicy::APPROVED_POLICY_SHA256,
            'source_request_sha256' => $sourceRequestSha256,
            'evaluation_input_sha256' => $evaluationInputSha256,
            'outcome' => $outcome,
            'reason_codes' => $reasonCodes,
            'advisory_codes' => $advisoryCodes,
            'evidence' => $evidence,
        ];
        $resultSha256 = $this->canonicalHash($evaluationRecord);
        $evaluationRecord['result_sha256'] = $resultSha256;
        $recordedPayload = [
            'operation_id' => $operationId,
            'opportunity_id' => $engineOpportunityId,
            ...$body,
        ];
        $evaluationPayload = [
            'evaluation_id' => $evaluationId,
            'opportunity_id' => $engineOpportunityId,
            'policy' => [
                'key' => TradingEngineEvaluationConsumptionPolicy::APPROVED_POLICY_KEY,
                'version' => TradingEngineEvaluationConsumptionPolicy::APPROVED_POLICY_VERSION,
                'algorithm_key' => TradingEngineEvaluationConsumptionPolicy::APPROVED_ALGORITHM_KEY,
                'algorithm_version' => TradingEngineEvaluationConsumptionPolicy::APPROVED_ALGORITHM_VERSION,
                'definition_sha256' => TradingEngineEvaluationConsumptionPolicy::APPROVED_POLICY_SHA256,
            ],
            'source' => [
                'request_sha256' => $sourceRequestSha256,
                'evaluation_input_sha256' => $evaluationInputSha256,
            ],
            'outcome' => $outcome,
            'reason_codes' => $reasonCodes,
            'advisory_codes' => $advisoryCodes,
            'evidence' => $evidence,
            'result_sha256' => $resultSha256,
        ];

        return [
            'engine_opportunity_id' => $engineOpportunityId,
            'evaluation_id' => $evaluationId,
            'recorded' => [
                'event_id' => $recordedEventId,
                'event_type' => 'opportunity.recorded.v1',
                'aggregate_type' => 'opportunity',
                'aggregate_id' => $engineOpportunityId,
                'causation_id' => $operationId,
                'idempotency_key' => 'opportunity:record:laravel:'.$opportunity->getKey().':v1',
                'payload' => $recordedPayload,
            ],
            'evaluated' => [
                'event_id' => $evaluationEventId,
                'event_type' => 'opportunity.evaluated.v1',
                'aggregate_type' => 'opportunity_evaluation',
                'aggregate_id' => $evaluationId,
                'causation_id' => $recordedEventId,
                'correlation_id' => 'correlation-'.$engineOpportunityId,
                'idempotency_key' => 'evaluation:'.$engineOpportunityId.':'.TradingEngineEvaluationConsumptionPolicy::APPROVED_POLICY_KEY.':1',
                'payload' => $evaluationPayload,
            ],
            'evaluation_record' => $evaluationRecord,
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function event(array $overrides, string $status = TradingEngineEvent::STATUS_PROJECTED): TradingEngineEvent
    {
        $payload = $overrides['payload'];

        return TradingEngineEvent::query()->create([
            'event_id' => $overrides['event_id'],
            'event_type' => $overrides['event_type'],
            'schema_version' => 1,
            'occurred_at' => now(),
            'producer' => 'trading-engine',
            'aggregate_type' => $overrides['aggregate_type'],
            'aggregate_id' => $overrides['aggregate_id'],
            'aggregate_version' => 1,
            'correlation_id' => $overrides['correlation_id'] ?? 'correlation-'.$overrides['aggregate_id'],
            'causation_id' => $overrides['causation_id'],
            'idempotency_key' => $overrides['idempotency_key'],
            'traceparent' => self::TRACEPARENT,
            'payload' => $payload,
            'payload_sha256' => $this->canonicalHash($payload),
            'raw_body_sha256' => str_repeat('a', 64),
            'event_envelope' => [],
            'handling_status' => $status,
            'handling_attempts' => $status === TradingEngineEvent::STATUS_PROJECTED ? 1 : 0,
            'received_at' => now(),
            'handled_at' => $status === TradingEngineEvent::STATUS_PROJECTED ? now() : null,
        ]);
    }

    private function networkId(Chain $chain): string
    {
        return match ($chain) {
            Chain::Solana => 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp',
            Chain::Ethereum => 'eip155:1',
        };
    }

    private function engineId(int $number): string
    {
        return '01KC'.str_pad((string) $number, 22, '0', STR_PAD_LEFT);
    }

    private function canonicalHash(mixed $value): string
    {
        return hash('sha256', json_encode(
            $this->canonicalValue($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function canonicalValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->canonicalValue(...), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalValue($item);
        }

        return $value;
    }
}
