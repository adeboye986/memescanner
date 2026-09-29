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
use App\Services\TradingEngine\TradingEngineEvaluationConsumptionDecision;
use App\Services\TradingEngine\TradingEngineEvaluationConsumptionPolicy;
use App\Services\UserTelegramNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TradingEngineEvaluationConsumptionPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const TRACEPARENT = '00-0123456789abcdef0123456789abcdef-0123456789abcdef-01';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-29 15:00:00');
        config()->set([
            'services.trading_engine.enabled' => true,
            'services.trading_engine.opportunity_export_enabled' => false,
            'services.trading_engine.opportunity_projection_enabled' => true,
            'services.trading_engine.evaluation_consumption_enabled' => true,
        ]);
    }

    #[DataProvider('disabledGateProvider')]
    public function test_required_feature_gates_fail_closed(
        string $configKey,
        string $reasonCode,
    ): void {
        config()->set($configKey, false);
        $opportunity = $this->opportunity(1);

        $decision = $this->policy()->assess($opportunity, null, null);

        $this->assertDecision($decision, false, $reasonCode);
    }

    /** @return array<string, array{string, string}> */
    public static function disabledGateProvider(): array
    {
        return [
            'engine' => ['services.trading_engine.enabled', 'ENGINE_INTEGRATION_DISABLED'],
            'projection' => ['services.trading_engine.opportunity_projection_enabled', 'OPPORTUNITY_PROJECTION_DISABLED'],
            'consumption' => ['services.trading_engine.evaluation_consumption_enabled', 'EVALUATION_CONSUMPTION_DISABLED'],
        ];
    }

    public function test_engine_and_projection_flags_do_not_imply_consumption(): void
    {
        config()->set([
            'services.trading_engine.enabled' => true,
            'services.trading_engine.opportunity_projection_enabled' => true,
            'services.trading_engine.evaluation_consumption_enabled' => false,
        ]);

        $decision = $this->policy()->assess($this->opportunity(2), null, null);

        $this->assertDecision($decision, false, 'EVALUATION_CONSUMPTION_DISABLED');
    }

    public function test_export_flag_is_independent_of_consumption(): void
    {
        $chain = $this->chain(3);

        config()->set('services.trading_engine.opportunity_export_enabled', false);
        $disabledExport = $this->assess($chain);
        config()->set('services.trading_engine.opportunity_export_enabled', true);
        $enabledExport = $this->assess($chain);

        $this->assertTrue($disabledExport->eligible);
        $this->assertTrue($enabledExport->eligible);
        $this->assertSame($disabledExport->decisionCode, $enabledExport->decisionCode);
        $this->assertSame($disabledExport->reasonCodes, $enabledExport->reasonCodes);
        $this->assertSame($disabledExport->evaluationId, $enabledExport->evaluationId);
        $this->assertSame($disabledExport->engineOpportunityId, $enabledExport->engineOpportunityId);
    }

    public function test_valid_projected_evaluation_is_eligible_only_for_future_decision_consideration(): void
    {
        $chain = $this->chain(4);

        $decision = $this->assess($chain);

        $this->assertTrue($decision->eligible);
        $this->assertSame(TradingEngineEvaluationConsumptionDecision::ELIGIBLE, $decision->decisionCode);
        $this->assertSame([], $decision->reasonCodes);
        $this->assertSame($chain['evaluation']->evaluation_id, $decision->evaluationId);
        $this->assertSame($chain['link']->engine_opportunity_id, $decision->engineOpportunityId);
    }

    public function test_missing_link_fails_closed(): void
    {
        $chain = $this->chain(5);

        $decision = $this->policy()->assess($chain['opportunity'], null, $chain['evaluation']);

        $this->assertDecision($decision, false, 'OPPORTUNITY_LINK_MISSING');
    }

    public function test_missing_evaluation_fails_closed(): void
    {
        $chain = $this->chain(6);

        $decision = $this->policy()->assess($chain['opportunity'], $chain['link'], null);

        $this->assertDecision($decision, false, 'EVALUATION_PROJECTION_MISSING');
    }

    public function test_cross_user_evaluation_fails_closed(): void
    {
        $first = $this->chain(7);
        $second = $this->chain(8);

        $decision = $this->policy()->assess(
            $first['opportunity'],
            $first['link'],
            $second['evaluation'],
        );

        $this->assertDecision($decision, false, 'EVALUATION_OPPORTUNITY_MISMATCH');
    }

    public function test_wrong_laravel_opportunity_fails_closed(): void
    {
        $chain = $this->chain(9);
        $other = $this->opportunity(10);

        $decision = $this->policy()->assess($other, $chain['link'], $chain['evaluation']);

        $this->assertDecision($decision, false, 'OPPORTUNITY_LINK_OPPORTUNITY_MISMATCH');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('linkMismatchProvider')]
    public function test_link_identity_mismatches_fail_closed(
        array $overrides,
        string $reasonCode,
    ): void {
        $chain = $this->chain(11, ['link' => $overrides]);

        $decision = $this->assess($chain);

        $this->assertDecision($decision, false, $reasonCode);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function linkMismatchProvider(): array
    {
        return [
            'user' => [['user_id' => 999999], 'OPPORTUNITY_LINK_USER_MISMATCH'],
            'network' => [['network_id' => 'eip155:1'], 'OPPORTUNITY_LINK_NETWORK_MISMATCH'],
            'asset' => [['asset_address' => 'DifferentAsset111111111111111111111111111'], 'OPPORTUNITY_LINK_ASSET_MISMATCH'],
            'discovery' => [['discovery_key' => str_repeat('f', 64)], 'OPPORTUNITY_LINK_DISCOVERY_MISMATCH'],
            'scanner' => [['scanner' => 'momentum'], 'OPPORTUNITY_LINK_SCANNER_MISMATCH'],
        ];
    }

    public function test_engine_opportunity_mismatch_fails_closed(): void
    {
        $chain = $this->chain(12, [
            'evaluation' => ['engine_opportunity_id' => $this->engineId(120)],
        ]);

        $decision = $this->assess($chain);

        $this->assertDecision($decision, false, 'ENGINE_OPPORTUNITY_MISMATCH');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('unapprovedPolicyProvider')]
    public function test_unapproved_policy_identity_fails_closed(array $overrides): void
    {
        $chain = $this->chain(13, ['evaluation' => $overrides]);

        $decision = $this->assess($chain);

        $this->assertDecision($decision, false, 'EVALUATION_POLICY_UNAPPROVED');
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function unapprovedPolicyProvider(): array
    {
        return [
            'key' => [['policy_key' => 'other-policy']],
            'version' => [['policy_version' => 2]],
            'algorithm' => [['algorithm_key' => 'other-algorithm']],
            'algorithm version' => [['algorithm_version' => 2]],
            'definition hash' => [['policy_definition_sha256' => str_repeat('0', 64)]],
        ];
    }

    #[DataProvider('sourceIntegrityProvider')]
    public function test_source_and_evaluation_hash_mismatches_fail_closed(
        string $option,
        string $reasonCode,
    ): void {
        $chain = $this->chain(14, [$option => str_repeat('0', 64)]);

        $decision = $this->assess($chain);

        $this->assertDecision($decision, false, $reasonCode);
    }

    /** @return array<string, array{string, string}> */
    public static function sourceIntegrityProvider(): array
    {
        return [
            'request hash' => ['source_request_sha256', 'SOURCE_REQUEST_HASH_MISMATCH'],
            'evaluation input hash' => ['evaluation_input_sha256', 'EVALUATION_INPUT_HASH_MISMATCH'],
            'result hash' => ['result_sha256', 'EVALUATION_RESULT_HASH_MISMATCH'],
        ];
    }

    public function test_broken_causal_relationship_fails_closed(): void
    {
        $chain = $this->chain(15, [
            'evaluation_event' => ['causation_id' => $this->engineId(999)],
        ]);

        $decision = $this->assess($chain);

        $this->assertDecision($decision, false, 'EVALUATION_EVENT_INTEGRITY_MISMATCH');
    }

    #[DataProvider('incompleteProjectionProvider')]
    public function test_non_projected_event_state_fails_closed(
        string $event,
        string $status,
    ): void {
        $chain = $this->chain(16, [$event.'_status' => $status]);

        $decision = $this->assess($chain);

        $this->assertDecision($decision, false, 'PROJECTION_EVENT_NOT_PROJECTED');
    }

    /** @return array<string, array{string, string}> */
    public static function incompleteProjectionProvider(): array
    {
        return [
            'recorded stored' => ['recorded', TradingEngineEvent::STATUS_STORED],
            'evaluation deferred' => ['evaluation', TradingEngineEvent::STATUS_DEFERRED],
            'evaluation retryable' => ['evaluation', TradingEngineEvent::STATUS_RETRYABLE],
            'evaluation failed' => ['evaluation', TradingEngineEvent::STATUS_FAILED],
        ];
    }

    public function test_projection_payload_mismatch_fails_closed(): void
    {
        $chain = $this->chain(17, [
            'evaluation_payload' => ['outcome' => 'failed'],
        ]);

        $decision = $this->assess($chain);

        $this->assertDecision($decision, false, 'EVALUATION_EVENT_INTEGRITY_MISMATCH');
    }

    #[DataProvider('nonConsumableOutcomeProvider')]
    public function test_only_actual_passed_outcome_is_consumable(
        string $outcome,
        string $reasonCode,
    ): void {
        $chain = $this->chain(18, [
            'outcome' => $outcome,
            'reason_codes' => $outcome === 'failed'
                ? ['LIQUIDITY_BELOW_MINIMUM']
                : ['LIQUIDITY_MISSING'],
        ]);

        $decision = $this->assess($chain);

        $this->assertDecision($decision, false, $reasonCode);
    }

    /** @return array<string, array{string, string}> */
    public static function nonConsumableOutcomeProvider(): array
    {
        return [
            'failed' => ['failed', 'EVALUATION_OUTCOME_FAILED'],
            'indeterminate' => ['indeterminate', 'EVALUATION_OUTCOME_INDETERMINATE'],
            'unsupported' => ['unknown', 'EVALUATION_OUTCOME_UNSUPPORTED'],
        ];
    }

    public function test_advisory_codes_are_preserved_as_information_and_never_trigger_execution(): void
    {
        Queue::fake();
        Notification::fake();
        Http::preventStrayRequests();
        $this->mock(UserTelegramNotificationService::class)->shouldNotReceive('send');
        $chain = $this->chain(19, [
            'advisory_codes' => ['SECURITY_EVIDENCE_UNAVAILABLE'],
        ]);

        $decision = $this->assess($chain);

        $this->assertTrue($decision->eligible);
        Queue::assertNothingPushed();
        Notification::assertNothingSent();
        $this->assertDatabaseCount('trade_opportunity_events', 0);
        $this->assertNoTradingState();
    }

    public function test_assessment_is_read_only_and_has_no_trading_or_notification_side_effects(): void
    {
        Queue::fake();
        Notification::fake();
        Http::preventStrayRequests();
        $this->mock(UserTelegramNotificationService::class)->shouldNotReceive('send');
        $chain = $this->chain(20);
        $before = [
            'opportunity' => $chain['opportunity']->fresh()->getRawOriginal(),
            'link' => $chain['link']->fresh()->getRawOriginal(),
            'evaluation' => $chain['evaluation']->fresh()->getRawOriginal(),
            'recorded' => $chain['recorded']->fresh()->getRawOriginal(),
            'evaluation_event' => $chain['evaluation_event']->fresh()->getRawOriginal(),
        ];

        $first = $this->assess($chain);
        $second = $this->assess($chain);

        $this->assertSame($first->eligible, $second->eligible);
        $this->assertSame($first->decisionCode, $second->decisionCode);
        $this->assertSame($first->reasonCodes, $second->reasonCodes);
        $this->assertSame($first->evaluationId, $second->evaluationId);
        $this->assertSame($first->engineOpportunityId, $second->engineOpportunityId);
        $this->assertTrue($first->eligible);
        $this->assertSame($before['opportunity'], $chain['opportunity']->fresh()->getRawOriginal());
        $this->assertSame($before['link'], $chain['link']->fresh()->getRawOriginal());
        $this->assertSame($before['evaluation'], $chain['evaluation']->fresh()->getRawOriginal());
        $this->assertSame($before['recorded'], $chain['recorded']->fresh()->getRawOriginal());
        $this->assertSame($before['evaluation_event'], $chain['evaluation_event']->fresh()->getRawOriginal());
        Queue::assertNothingPushed();
        Notification::assertNothingSent();
        $this->assertDatabaseCount('trade_opportunity_events', 0);
        $this->assertNoTradingState();
    }

    private function policy(): TradingEngineEvaluationConsumptionPolicy
    {
        return app(TradingEngineEvaluationConsumptionPolicy::class);
    }

    /**
     * @param  array{
     *   opportunity: TradeOpportunity,
     *   link: TradingEngineOpportunityLink,
     *   evaluation: TradingEngineOpportunityEvaluation
     * }  $chain
     */
    private function assess(array $chain): TradingEngineEvaluationConsumptionDecision
    {
        return $this->policy()->assess(
            $chain['opportunity'],
            $chain['link'],
            $chain['evaluation'],
        );
    }

    private function assertDecision(
        TradingEngineEvaluationConsumptionDecision $decision,
        bool $eligible,
        string $reasonCode,
    ): void {
        $this->assertSame($eligible, $decision->eligible);
        $this->assertSame(TradingEngineEvaluationConsumptionDecision::INELIGIBLE, $decision->decisionCode);
        $this->assertSame([$reasonCode], $decision->reasonCodes);
        $this->assertNull($decision->evaluationId);
        $this->assertNull($decision->engineOpportunityId);
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
                'qualified_at' => '2026-09-29T15:00:00.000Z',
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
            'handling_status' => $options['recorded_status'] ?? TradingEngineEvent::STATUS_PROJECTED,
            ...($options['recorded_event'] ?? []),
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
            ...($options['link'] ?? []),
        ]);
        $sourceRequestSha256 = $options['source_request_sha256'] ?? $this->canonicalHash($body);
        $evaluationInputSha256 = $options['evaluation_input_sha256'] ?? $this->canonicalHash([
            'policy_definition_sha256' => TradingEngineEvaluationConsumptionPolicy::APPROVED_POLICY_SHA256,
            'source_request_sha256' => $sourceRequestSha256,
        ]);
        $outcome = $options['outcome'] ?? 'passed';
        $reasonCodes = $options['reason_codes'] ?? [];
        $advisoryCodes = $options['advisory_codes'] ?? [];
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
        $resultSha256 = $options['result_sha256'] ?? $this->canonicalHash([
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
            ...($options['evaluation_payload'] ?? []),
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
            'handling_status' => $options['evaluation_status'] ?? TradingEngineEvent::STATUS_PROJECTED,
            ...($options['evaluation_event'] ?? []),
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
            ...($options['evaluation'] ?? []),
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
            'discovery_key' => hash('sha256', 'discovery-'.$sequence),
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
        $event = [
            'event_id' => $overrides['event_id'],
            'event_type' => $overrides['event_type'],
            'schema_version' => 1,
            'occurred_at' => now(),
            'producer' => 'trading-engine',
            'aggregate_type' => $overrides['aggregate_type'],
            'aggregate_id' => $overrides['aggregate_id'],
            'aggregate_version' => 1,
            'correlation_id' => 'correlation-'.$overrides['aggregate_id'],
            'causation_id' => $overrides['causation_id'],
            'idempotency_key' => $overrides['idempotency_key'],
            'traceparent' => self::TRACEPARENT,
            'payload' => $payload,
            'payload_sha256' => $this->canonicalHash($payload),
            'raw_body_sha256' => str_repeat('a', 64),
            'event_envelope' => [],
            'handling_status' => $overrides['handling_status'],
            'handling_attempts' => 1,
            'received_at' => now(),
            'handled_at' => $overrides['handling_status'] === TradingEngineEvent::STATUS_PROJECTED
                ? now()
                : null,
        ];

        foreach ($overrides as $key => $value) {
            if (! array_key_exists($key, $event) && $key !== 'payload') {
                continue;
            }

            $event[$key] = $value;
        }

        $event['payload_sha256'] = $this->canonicalHash($event['payload']);

        return TradingEngineEvent::query()->create($event);
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
