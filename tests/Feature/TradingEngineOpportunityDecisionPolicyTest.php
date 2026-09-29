<?php

namespace Tests\Feature;

use App\Chain;
use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Enums\TradeOpportunityStatus;
use App\Models\TradeOpportunity;
use App\Models\TradingEngineEvent;
use App\Models\TradingEngineOpportunityEvaluation;
use App\Models\TradingEngineOpportunityLink;
use App\Models\User;
use App\Models\UserTradingPreference;
use App\Services\ApplicationSettingsService;
use App\Services\OpportunityActionService;
use App\Services\TradeExecutionManager;
use App\Services\Trading\LiveTradeExecutor;
use App\Services\Trading\PaperTradeExecutor;
use App\Services\TradingEngine\TradingEngineEvaluationConsumptionDecision;
use App\Services\TradingEngine\TradingEngineEvaluationConsumptionPolicy;
use App\Services\TradingEngine\TradingEngineOpportunityDecision;
use App\Services\TradingEngine\TradingEngineOpportunityDecisionPolicy;
use App\Services\UserTelegramNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TradingEngineOpportunityDecisionPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const TRACEPARENT = '00-0123456789abcdef0123456789abcdef-0123456789abcdef-01';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-29 18:00:00');
        config()->set([
            'services.trading_engine.enabled' => true,
            'services.trading_engine.opportunity_export_enabled' => false,
            'services.trading_engine.opportunity_projection_enabled' => true,
            'services.trading_engine.evaluation_consumption_enabled' => true,
            'services.trading_engine.decision_boundary_enabled' => true,
        ]);
    }

    public function test_decision_boundary_flag_fails_closed_after_valid_consumption(): void
    {
        $chain = $this->chain(1, [
            'opportunity' => ['entry_mode' => EntryMode::Auto],
        ]);
        config()->set('services.trading_engine.decision_boundary_enabled', false);

        $decision = $this->assess($chain);

        $this->assertDecision(
            $decision,
            TradingEngineOpportunityDecision::WOULD_REJECT,
            'DECISION_BOUNDARY_DISABLED',
        );
        $this->assertSame(TradingEngineEvaluationConsumptionDecision::ELIGIBLE, $decision->consumptionDecisionCode);
        $this->assertSame($chain['evaluation']->evaluation_id, $decision->evaluationId);
        $this->assertSame($chain['link']->engine_opportunity_id, $decision->engineOpportunityId);
    }

    public function test_underlying_consumption_gate_cannot_be_bypassed(): void
    {
        $chain = $this->chain(2, [
            'opportunity' => ['entry_mode' => EntryMode::Auto],
            'source_request_sha256' => str_repeat('0', 64),
        ]);

        $decision = $this->assess($chain);

        $this->assertDecision(
            $decision,
            TradingEngineOpportunityDecision::WOULD_REJECT,
            'SOURCE_REQUEST_HASH_MISMATCH',
        );
        $this->assertSame(TradingEngineEvaluationConsumptionDecision::INELIGIBLE, $decision->consumptionDecisionCode);
        $this->assertNull($decision->evaluationId);
        $this->assertNull($decision->engineOpportunityId);
    }

    public function test_failed_engine_evaluation_can_never_produce_an_enter_decision(): void
    {
        $chain = $this->chain(3, [
            'opportunity' => ['entry_mode' => EntryMode::Auto],
            'outcome' => 'failed',
            'reason_codes' => ['LIQUIDITY_BELOW_MINIMUM'],
        ]);

        $decision = $this->assess($chain);

        $this->assertDecision(
            $decision,
            TradingEngineOpportunityDecision::WOULD_REJECT,
            'EVALUATION_OUTCOME_FAILED',
        );
        $this->assertSame(TradingEngineEvaluationConsumptionDecision::INELIGIBLE, $decision->consumptionDecisionCode);
    }

    public function test_ineligible_consumption_value_cannot_be_promoted_by_decision_factory(): void
    {
        $consumption = TradingEngineEvaluationConsumptionDecision::ineligible('EVALUATION_OUTCOME_FAILED');

        $decision = TradingEngineOpportunityDecision::wouldEnter(
            $consumption,
            'AUTO_ENTRY_CONFIGURED',
        );

        $this->assertDecision(
            $decision,
            TradingEngineOpportunityDecision::WOULD_REJECT,
            'EVALUATION_OUTCOME_FAILED',
        );
        $this->assertSame(TradingEngineEvaluationConsumptionDecision::INELIGIBLE, $decision->consumptionDecisionCode);
    }

    #[DataProvider('entryModeDecisionProvider')]
    public function test_existing_entry_mode_semantics_are_represented_without_execution(
        EntryMode $entryMode,
        string $decisionCode,
        string $reasonCode,
    ): void {
        $chain = $this->chain(4, [
            'opportunity' => [
                'entry_mode' => $entryMode,
                'status' => TradeOpportunityStatus::Qualified,
            ],
        ]);

        $decision = $this->assess($chain);

        $this->assertDecision($decision, $decisionCode, $reasonCode);
        $this->assertSame(TradingEngineEvaluationConsumptionDecision::ELIGIBLE, $decision->consumptionDecisionCode);
        $this->assertSame(TradeOpportunityStatus::Qualified, $chain['opportunity']->fresh()->status);
        $this->assertDatabaseCount('paper_positions', 0);
        $this->assertDatabaseCount('live_positions', 0);
    }

    /** @return array<string, array{EntryMode, string, string}> */
    public static function entryModeDecisionProvider(): array
    {
        return [
            'automatic entry would enter' => [
                EntryMode::Auto,
                TradingEngineOpportunityDecision::WOULD_ENTER,
                'AUTO_ENTRY_CONFIGURED',
            ],
            'confirm entry would hold' => [
                EntryMode::Confirm,
                TradingEngineOpportunityDecision::WOULD_HOLD,
                'CONFIRMATION_REQUIRED',
            ],
            'signal entry would hold' => [
                EntryMode::Signal,
                TradingEngineOpportunityDecision::WOULD_HOLD,
                'SIGNAL_ONLY',
            ],
        ];
    }

    public function test_kill_switch_preserves_existing_reject_semantics(): void
    {
        app(ApplicationSettingsService::class)->update(['risk.kill_switch' => true]);
        $chain = $this->chain(5, [
            'opportunity' => ['entry_mode' => EntryMode::Auto],
        ]);

        $decision = $this->assess($chain);

        $this->assertDecision(
            $decision,
            TradingEngineOpportunityDecision::WOULD_REJECT,
            'KILL_SWITCH_ACTIVE',
        );
        $this->assertSame(TradeOpportunityStatus::PendingConfirmation, $chain['opportunity']->fresh()->status);
        $this->assertNoTradingState();
    }

    public function test_disabled_user_preserves_existing_reject_semantics(): void
    {
        $chain = $this->chain(6, [
            'opportunity' => ['entry_mode' => EntryMode::Auto],
        ]);
        UserTradingPreference::factory()->create([
            'user_id' => $chain['opportunity']->user_id,
            'execution_mode' => ExecutionMode::Paper,
            'entry_mode' => EntryMode::Auto,
            'trading_enabled' => false,
        ]);

        $decision = $this->assess($chain);

        $this->assertDecision(
            $decision,
            TradingEngineOpportunityDecision::WOULD_REJECT,
            'USER_TRADING_DISABLED',
        );
        $this->assertSame(TradeOpportunityStatus::PendingConfirmation, $chain['opportunity']->fresh()->status);
        $this->assertNoTradingState();
    }

    public function test_export_flag_is_independent_of_decision_boundary(): void
    {
        $chain = $this->chain(7, [
            'opportunity' => ['entry_mode' => EntryMode::Auto],
        ]);

        config()->set('services.trading_engine.opportunity_export_enabled', false);
        $disabledExport = $this->assess($chain);
        config()->set('services.trading_engine.opportunity_export_enabled', true);
        $enabledExport = $this->assess($chain);

        $this->assertSame($disabledExport->decisionCode, $enabledExport->decisionCode);
        $this->assertSame($disabledExport->reasonCodes, $enabledExport->reasonCodes);
        $this->assertSame($disabledExport->consumptionDecisionCode, $enabledExport->consumptionDecisionCode);
        $this->assertSame($disabledExport->evaluationId, $enabledExport->evaluationId);
        $this->assertSame($disabledExport->engineOpportunityId, $enabledExport->engineOpportunityId);
        $this->assertSame(TradingEngineOpportunityDecision::WOULD_ENTER, $enabledExport->decisionCode);
    }

    public function test_assessment_is_deterministic_read_only_and_has_no_execution_side_effects(): void
    {
        $chain = $this->chain(8, [
            'opportunity' => [
                'entry_mode' => EntryMode::Auto,
                'status' => TradeOpportunityStatus::Qualified,
                'execution_data' => ['unchanged' => true],
            ],
        ]);
        $before = [
            'opportunity' => $chain['opportunity']->fresh()->getRawOriginal(),
            'link' => $chain['link']->fresh()->getRawOriginal(),
            'evaluation' => $chain['evaluation']->fresh()->getRawOriginal(),
            'recorded' => $chain['recorded']->fresh()->getRawOriginal(),
            'evaluation_event' => $chain['evaluation_event']->fresh()->getRawOriginal(),
        ];
        Queue::fake();
        Notification::fake();
        Http::preventStrayRequests();
        $this->mock(TradeExecutionManager::class)->shouldNotReceive('execute');
        $this->mock(OpportunityActionService::class)->shouldNotReceive('approve', 'ignore');
        $this->mock(PaperTradeExecutor::class)->shouldNotReceive('execute', 'sendNotification');
        $this->mock(LiveTradeExecutor::class)->shouldNotReceive('execute');
        $this->mock(UserTelegramNotificationService::class)->shouldNotReceive('send');

        $first = $this->assess($chain);
        $second = $this->assess($chain);

        $this->assertSame(TradingEngineOpportunityDecision::WOULD_ENTER, $first->decisionCode);
        $this->assertSame($first->decisionCode, $second->decisionCode);
        $this->assertSame($first->reasonCodes, $second->reasonCodes);
        $this->assertSame($first->consumptionDecisionCode, $second->consumptionDecisionCode);
        $this->assertSame($first->evaluationId, $second->evaluationId);
        $this->assertSame($first->engineOpportunityId, $second->engineOpportunityId);
        $this->assertSame($before['opportunity'], $chain['opportunity']->fresh()->getRawOriginal());
        $this->assertSame($before['link'], $chain['link']->fresh()->getRawOriginal());
        $this->assertSame($before['evaluation'], $chain['evaluation']->fresh()->getRawOriginal());
        $this->assertSame($before['recorded'], $chain['recorded']->fresh()->getRawOriginal());
        $this->assertSame($before['evaluation_event'], $chain['evaluation_event']->fresh()->getRawOriginal());
        $this->assertDatabaseCount('trading_engine_event_inbox', 2);
        $this->assertDatabaseCount('trading_engine_opportunity_links', 1);
        $this->assertDatabaseCount('trading_engine_opportunity_evaluations', 1);
        $this->assertDatabaseCount('user_trading_preferences', 0);
        $this->assertDatabaseCount('trade_opportunity_events', 0);
        $this->assertDatabaseCount('application_settings', 0);
        $this->assertDatabaseCount('setting_audits', 0);
        Queue::assertNothingPushed();
        Notification::assertNothingSent();
        Http::assertNothingSent();
        $this->assertNoTradingState();
    }

    private function policy(): TradingEngineOpportunityDecisionPolicy
    {
        return app(TradingEngineOpportunityDecisionPolicy::class);
    }

    /**
     * @param  array{
     *   opportunity: TradeOpportunity,
     *   link: TradingEngineOpportunityLink,
     *   evaluation: TradingEngineOpportunityEvaluation
     * }  $chain
     */
    private function assess(array $chain): TradingEngineOpportunityDecision
    {
        return $this->policy()->assess(
            $chain['opportunity'],
            $chain['link'],
            $chain['evaluation'],
        );
    }

    private function assertDecision(
        TradingEngineOpportunityDecision $decision,
        string $decisionCode,
        string $reasonCode,
    ): void {
        $this->assertSame($decisionCode, $decision->decisionCode);
        $this->assertSame([$reasonCode], $decision->reasonCodes);
    }

    private function assertNoTradingState(): void
    {
        $this->assertDatabaseCount('paper_wallets', 0);
        $this->assertDatabaseCount('paper_positions', 0);
        $this->assertDatabaseCount('live_positions', 0);
        $this->assertDatabaseCount('connected_wallets', 0);
        $this->assertDatabaseCount('solana_swap_attempts', 0);
        $this->assertDatabaseCount('ethereum_swap_attempts', 0);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{
     *   opportunity: TradeOpportunity,
     *   recorded: TradingEngineEvent,
     *   link: TradingEngineOpportunityLink,
     *   evaluation_event: TradingEngineEvent,
     *   evaluation: TradingEngineOpportunityEvaluation
     * }
     */
    private function chain(int $sequence, array $options = []): array
    {
        $opportunity = $this->opportunity($sequence, $options['opportunity'] ?? []);
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
            'network' => ['id' => 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp'],
            'asset' => [
                'address' => $opportunity->address,
                'symbol' => $opportunity->symbol,
            ],
            'market_snapshot' => [
                'market_cap_usd' => ['value' => '12000', 'provider' => 'birdeye'],
                'liquidity_usd' => ['value' => '1000', 'provider' => 'birdeye'],
            ],
            'qualification' => [
                'qualified_at' => '2026-09-29T18:00:00.000Z',
                'discovery_market_cap_usd' => '10000',
                'move_since_discovery_percent' => '20',
                'classification' => 'strong',
            ],
            'security' => [
                'status' => 'passed',
                'provider' => 'goplus',
                'passed' => true,
            ],
        ];
        $recordedPayload = [
            'operation_id' => $operationId,
            'opportunity_id' => $engineOpportunityId,
            ...$body,
        ];
        $recorded = $this->event([
            'event_id' => $recordedEventId,
            'event_type' => 'opportunity.recorded.v1',
            'aggregate_type' => 'opportunity',
            'aggregate_id' => $engineOpportunityId,
            'causation_id' => $operationId,
            'idempotency_key' => 'opportunity:record:laravel:'.$opportunity->getKey().':v1',
            'payload' => $recordedPayload,
        ]);
        $link = TradingEngineOpportunityLink::query()->create([
            'trade_opportunity_id' => $opportunity->getKey(),
            'engine_opportunity_id' => $engineOpportunityId,
            'recorded_event_id' => $recorded->event_id,
            'user_id' => $opportunity->user_id,
            'discovery_key' => $opportunity->discovery_key,
            'scanner' => $opportunity->scanner,
            'network_id' => 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp',
            'asset_address' => $opportunity->address,
            'recorded_at' => $recorded->occurred_at,
            'linked_at' => now(),
        ]);
        $sourceRequestSha256 = $options['source_request_sha256'] ?? $this->canonicalHash($body);
        $evaluationInputSha256 = $this->canonicalHash([
            'policy_definition_sha256' => TradingEngineEvaluationConsumptionPolicy::APPROVED_POLICY_SHA256,
            'source_request_sha256' => $sourceRequestSha256,
        ]);
        $outcome = $options['outcome'] ?? 'passed';
        $reasonCodes = $options['reason_codes'] ?? [];
        $advisoryCodes = [];
        $evidence = [
            'profile' => 'solana:new-token',
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
        $resultSha256 = $this->canonicalHash([
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
        ]);
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
        $evaluationEvent = $this->event([
            'event_id' => $evaluationEventId,
            'event_type' => 'opportunity.evaluated.v1',
            'aggregate_type' => 'opportunity_evaluation',
            'aggregate_id' => $evaluationId,
            'causation_id' => $recorded->event_id,
            'correlation_id' => $recorded->correlation_id,
            'idempotency_key' => 'evaluation:'.$engineOpportunityId.':'.TradingEngineEvaluationConsumptionPolicy::APPROVED_POLICY_KEY.':1',
            'payload' => $evaluationPayload,
        ]);
        $evaluation = TradingEngineOpportunityEvaluation::query()->create([
            'opportunity_link_id' => $link->getKey(),
            'trade_opportunity_id' => $opportunity->getKey(),
            'evaluation_id' => $evaluationId,
            'engine_opportunity_id' => $engineOpportunityId,
            'evaluation_event_id' => $evaluationEvent->event_id,
            'recorded_event_id' => $recorded->event_id,
            'policy_key' => TradingEngineEvaluationConsumptionPolicy::APPROVED_POLICY_KEY,
            'policy_version' => TradingEngineEvaluationConsumptionPolicy::APPROVED_POLICY_VERSION,
            'algorithm_key' => TradingEngineEvaluationConsumptionPolicy::APPROVED_ALGORITHM_KEY,
            'algorithm_version' => TradingEngineEvaluationConsumptionPolicy::APPROVED_ALGORITHM_VERSION,
            'policy_definition_sha256' => TradingEngineEvaluationConsumptionPolicy::APPROVED_POLICY_SHA256,
            'source_request_sha256' => $sourceRequestSha256,
            'evaluation_input_sha256' => $evaluationInputSha256,
            'result_sha256' => $resultSha256,
            'outcome' => $outcome,
            'reason_codes' => $reasonCodes,
            'advisory_codes' => $advisoryCodes,
            'evidence' => $evidence,
            'correlation_id' => $evaluationEvent->correlation_id,
            'traceparent' => $evaluationEvent->traceparent,
            'evaluated_at' => $evaluationEvent->occurred_at,
            'event_received_at' => $evaluationEvent->received_at,
            'projected_at' => now(),
        ]);

        return [
            'opportunity' => $opportunity,
            'recorded' => $recorded,
            'link' => $link,
            'evaluation_event' => $evaluationEvent,
            'evaluation' => $evaluation,
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function opportunity(int $sequence, array $overrides = []): TradeOpportunity
    {
        return TradeOpportunity::factory()->for(User::factory())->create([
            'chain' => Chain::Solana,
            'discovery_key' => hash('sha256', 'decision-discovery-'.$sequence),
            'address' => 'So11111111111111111111111111111111111111112',
            'symbol' => 'TEST',
            'scanner' => 'new-token',
            'status' => TradeOpportunityStatus::PendingConfirmation,
            'execution_mode' => ExecutionMode::Paper,
            'entry_mode' => EntryMode::Confirm,
            'execution_data' => ['unchanged' => true],
            'qualified_at' => now(),
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function event(array $overrides): TradingEngineEvent
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
            'handling_status' => TradingEngineEvent::STATUS_PROJECTED,
            'handling_attempts' => 1,
            'received_at' => now(),
            'handled_at' => now(),
        ]);
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
