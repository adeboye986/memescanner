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
use App\Services\TradingEngine\TradingEngineOpportunityProjector;
use App\Services\TradingEngine\TradingEngineProjectionReconciler;
use App\Services\UserTelegramNotificationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class TradingEngineProjectionReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private const ENGINE_OPPORTUNITY_ID = '01K9ABCDEFGHJKMNPQRSTVWXYZ';

    private const EVALUATION_EVENT_ID = '01K9ZYXWVTSRQPNMKJHGFEDCBC';

    private const EVALUATION_ID = '01K9ZYXWVTSRQPNMKJHGFEDCBA';

    private const RECORDED_EVENT_ID = '01K9ZYXWVTSRQPNMKJHGFEDCBB';

    private const TRACEPARENT = '00-0123456789abcdef0123456789abcdef-0123456789abcdef-01';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-29 12:00:00');
        config()->set([
            'services.trading_engine.enabled' => true,
            'services.trading_engine.opportunity_export_enabled' => false,
            'services.trading_engine.opportunity_projection_enabled' => true,
        ]);
    }

    public function test_clean_recorded_and_evaluated_causal_chain_has_zero_issues(): void
    {
        $chain = $this->cleanChain();

        $result = app(TradingEngineProjectionReconciler::class)->reconcile();

        $this->assertSame([
            'projected_recorded_events' => 1,
            'projected_evaluated_events' => 1,
            'opportunity_links' => 1,
            'evaluation_projections' => 1,
        ], $result['counts']);
        $this->assertSame([
            'recorded_events' => 1,
            'evaluated_events' => 1,
            'opportunity_links' => 1,
            'evaluations' => 1,
        ], $result['checked']);
        $this->assertSame(0, $result['issue_count']);
        $this->assertFalse($result['issues_truncated']);
        $this->assertSame([], $result['issues']);
        $this->assertSame(TradingEngineEvent::STATUS_PROJECTED, $chain['recorded']->fresh()->handling_status);
        $this->assertSame(TradingEngineEvent::STATUS_PROJECTED, $chain['evaluation']->fresh()->handling_status);
    }

    public function test_projected_recorded_event_without_link_is_detected(): void
    {
        $opportunity = $this->opportunity();
        $event = $this->storeEvent($this->recordedEvent($opportunity));
        $this->markProjected($event);

        $result = app(TradingEngineProjectionReconciler::class)->reconcile();

        $this->assertIssue($result, 'OPPORTUNITY_LINK_MISSING', $event->event_id);
    }

    public function test_projected_evaluated_event_without_projection_is_detected(): void
    {
        $opportunity = $this->opportunity();
        $recorded = $this->storeEvent($this->recordedEvent($opportunity));
        app(TradingEngineOpportunityProjector::class)->project($recorded->event_id);
        $evaluation = $this->storeEvent($this->evaluatedEvent());
        $this->markProjected($evaluation);

        $result = app(TradingEngineProjectionReconciler::class)->reconcile();

        $this->assertIssue($result, 'EVALUATION_PROJECTION_MISSING', $evaluation->event_id);
        $this->assertNotContains('BROKEN_CAUSAL_CHAIN', array_column($result['issues'], 'code'));
    }

    public function test_recorded_projection_identity_mismatch_is_detected(): void
    {
        $opportunity = $this->opportunity();
        $event = $this->storeEvent($this->recordedEvent($opportunity));
        $this->markProjected($event);
        $this->createLink($opportunity, $event, ['scanner' => 'wrong-scanner']);

        $result = app(TradingEngineProjectionReconciler::class)->reconcile();

        $this->assertIssue($result, 'OPPORTUNITY_LINK_IDENTITY_MISMATCH', $event->event_id);
    }

    public function test_evaluation_policy_source_result_correlation_and_timestamp_mismatches_are_detected(): void
    {
        $opportunity = $this->opportunity();
        $recorded = $this->storeEvent($this->recordedEvent($opportunity));
        app(TradingEngineOpportunityProjector::class)->project($recorded->event_id);
        $link = TradingEngineOpportunityLink::query()->sole();
        $evaluation = $this->storeEvent($this->evaluatedEvent());
        $this->createEvaluationProjection($evaluation, $link, [
            'policy_key' => 'wrong-policy',
            'source_request_sha256' => str_repeat('0', 64),
            'result_sha256' => str_repeat('1', 64),
            'correlation_id' => 'wrong-correlation',
            'evaluated_at' => now()->subDay(),
        ]);
        $this->markProjected($evaluation);

        $result = app(TradingEngineProjectionReconciler::class)->reconcile();
        $codes = array_column($result['issues'], 'code');

        $this->assertContains('EVALUATION_POLICY_MISMATCH', $codes);
        $this->assertContains('EVALUATION_SOURCE_HASH_MISMATCH', $codes);
        $this->assertContains('EVALUATION_RESULT_MISMATCH', $codes);
        $this->assertContains('EVALUATION_CORRELATION_MISMATCH', $codes);
        $this->assertContains('EVALUATION_TIMESTAMP_MISMATCH', $codes);
    }

    public function test_broken_evaluated_to_recorded_causal_chain_is_detected(): void
    {
        $opportunity = $this->opportunity();
        $recorded = $this->storeEvent($this->recordedEvent($opportunity));
        app(TradingEngineOpportunityProjector::class)->project($recorded->event_id);
        $link = TradingEngineOpportunityLink::query()->sole();
        $evaluation = $this->storeEvent($this->evaluatedEvent($this->eventId(99)));
        $this->createEvaluationProjection($evaluation, $link);
        $this->markProjected($evaluation);

        $result = app(TradingEngineProjectionReconciler::class)->reconcile();

        $this->assertIssue($result, 'BROKEN_CAUSAL_CHAIN', $evaluation->event_id);
        $this->assertIssue($result, 'EVALUATION_EVENT_CONTRACT_MISMATCH', $evaluation->event_id);
    }

    public function test_foreign_keys_make_orphan_link_and_evaluation_fixtures_impossible_through_normal_writes(): void
    {
        $chain = $this->cleanChain();

        try {
            DB::table('trading_engine_event_inbox')
                ->where('event_id', $chain['recorded']->event_id)
                ->delete();
            $this->fail('The recorded source event foreign key was not enforced.');
        } catch (QueryException) {
        }

        try {
            DB::table('trading_engine_event_inbox')
                ->where('event_id', $chain['evaluation']->event_id)
                ->delete();
            $this->fail('The evaluation source event foreign key was not enforced.');
        } catch (QueryException) {
        }

        $result = app(TradingEngineProjectionReconciler::class)->reconcile();
        $codes = array_column($result['issues'], 'code');

        $this->assertNotContains('ORPHAN_OPPORTUNITY_LINK', $codes);
        $this->assertNotContains('ORPHAN_EVALUATION', $codes);
        $this->assertSame(0, $result['issue_count']);
    }

    public function test_in_flight_and_unhandled_events_without_projections_are_not_corruption(): void
    {
        foreach ([
            TradingEngineEvent::STATUS_STORED,
            TradingEngineEvent::STATUS_RETRYABLE,
            TradingEngineEvent::STATUS_DEFERRED,
            TradingEngineEvent::STATUS_UNHANDLED,
        ] as $index => $status) {
            $event = $this->storeEvent($this->recordedEvent($this->opportunity(), $this->eventId(110 + $index)));
            $event->forceFill(['handling_status' => $status])->save();
        }

        $result = app(TradingEngineProjectionReconciler::class)->reconcile();

        $this->assertSame(0, $result['counts']['projected_recorded_events']);
        $this->assertSame(0, $result['issue_count']);
    }

    public function test_clean_command_is_read_only_dispatches_nothing_and_works_when_runtime_flags_are_disabled(): void
    {
        Queue::fake([ProjectTradingEngineEvent::class]);
        Notification::fake();
        $this->mock(UserTelegramNotificationService::class)->shouldNotReceive('send');
        $chain = $this->cleanChain();
        config()->set([
            'services.trading_engine.enabled' => false,
            'services.trading_engine.opportunity_export_enabled' => false,
            'services.trading_engine.opportunity_projection_enabled' => false,
        ]);
        $before = $this->auditState();
        $opportunityBefore = $chain['opportunity']->fresh()->getAttributes();

        $this->artisan('trading-engine:reconcile-projections')
            ->expectsOutputToContain('Integrity issue count: 0')
            ->expectsOutputToContain('without integrity issues')
            ->assertSuccessful();

        $this->assertSame($before, $this->auditState());
        $this->assertEquals($opportunityBefore, $chain['opportunity']->fresh()->getAttributes());
        Queue::assertNothingPushed();
        Notification::assertNothingSent();
        $this->assertDatabaseCount('trade_opportunity_events', 0);
        $this->assertDatabaseCount('paper_wallets', 0);
        $this->assertDatabaseCount('paper_positions', 0);
        $this->assertDatabaseCount('solana_swap_attempts', 0);
        $this->assertDatabaseCount('ethereum_swap_attempts', 0);
        $this->assertDatabaseCount('live_positions', 0);
        $this->assertDatabaseCount('connected_wallets', 0);
    }

    public function test_issue_command_returns_failure_and_never_prints_sensitive_payload_material(): void
    {
        Queue::fake([ProjectTradingEngineEvent::class]);
        $opportunity = $this->opportunity();
        $eventData = $this->recordedEvent($opportunity);
        $eventData['payload']['operator_secret'] = 'never-print-this-private-secret';
        $event = $this->storeEvent($eventData);
        $event->forceFill([
            'event_envelope' => ['signature' => 'never-print-this-signature'],
        ])->save();
        $this->markProjected($event);

        $exitCode = Artisan::call('trading-engine:reconcile-projections');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('OPPORTUNITY_LINK_MISSING', $output);
        $this->assertStringNotContainsString('never-print-this-private-secret', $output);
        $this->assertStringNotContainsString('never-print-this-signature', $output);
        Queue::assertNothingPushed();
    }

    public function test_command_normalizes_infrastructure_failure_without_exposing_raw_exception(): void
    {
        $reconciler = Mockery::mock(TradingEngineProjectionReconciler::class);
        $reconciler->shouldReceive('reconcile')
            ->once()
            ->andThrow(new RuntimeException('database password private-key raw failure'));
        $this->app->instance(TradingEngineProjectionReconciler::class, $reconciler);

        $exitCode = Artisan::call('trading-engine:reconcile-projections');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('could not be completed safely', $output);
        $this->assertStringNotContainsString('database password', $output);
        $this->assertStringNotContainsString('private-key', $output);
    }

    /**
     * @return array{opportunity: TradeOpportunity, recorded: TradingEngineEvent, evaluation: TradingEngineEvent}
     */
    private function cleanChain(): array
    {
        $opportunity = $this->opportunity();
        $recorded = $this->storeEvent($this->recordedEvent($opportunity));
        $evaluation = $this->storeEvent($this->evaluatedEvent());
        $projector = app(TradingEngineOpportunityProjector::class);
        $projector->project($recorded->event_id);
        $projector->project($evaluation->event_id);

        return compact('opportunity', 'recorded', 'evaluation');
    }

    private function opportunity(): TradeOpportunity
    {
        $user = User::factory()->create();

        return TradeOpportunity::factory()->for($user)->create([
            'chain' => Chain::Solana,
            'discovery_key' => str_repeat('a', 64),
            'address' => 'So11111111111111111111111111111111111111112',
            'scanner' => 'new-token',
            'status' => TradeOpportunityStatus::PendingConfirmation,
            'execution_mode' => ExecutionMode::Paper,
            'entry_mode' => EntryMode::Confirm,
            'execution_data' => ['unchanged' => true],
        ]);
    }

    private function createLink(
        TradeOpportunity $opportunity,
        TradingEngineEvent $event,
        array $overrides = [],
    ): TradingEngineOpportunityLink {
        return TradingEngineOpportunityLink::query()->create([
            'trade_opportunity_id' => $opportunity->getKey(),
            'engine_opportunity_id' => self::ENGINE_OPPORTUNITY_ID,
            'recorded_event_id' => $event->event_id,
            'user_id' => $opportunity->user_id,
            'discovery_key' => $opportunity->discovery_key,
            'scanner' => $opportunity->scanner,
            'network_id' => 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp',
            'asset_address' => $opportunity->address,
            'recorded_at' => $event->occurred_at,
            'linked_at' => now(),
            ...$overrides,
        ]);
    }

    private function createEvaluationProjection(
        TradingEngineEvent $event,
        TradingEngineOpportunityLink $link,
        array $overrides = [],
    ): TradingEngineOpportunityEvaluation {
        $payload = $event->payload;
        $policy = $payload['policy'];
        $source = $payload['source'];

        return TradingEngineOpportunityEvaluation::query()->create([
            'opportunity_link_id' => $link->getKey(),
            'trade_opportunity_id' => $link->trade_opportunity_id,
            'evaluation_id' => $payload['evaluation_id'],
            'engine_opportunity_id' => $payload['opportunity_id'],
            'evaluation_event_id' => $event->event_id,
            'recorded_event_id' => $link->recorded_event_id,
            'policy_key' => $policy['key'],
            'policy_version' => $policy['version'],
            'algorithm_key' => $policy['algorithm_key'],
            'algorithm_version' => $policy['algorithm_version'],
            'policy_definition_sha256' => $policy['definition_sha256'],
            'source_request_sha256' => $source['request_sha256'],
            'evaluation_input_sha256' => $source['evaluation_input_sha256'],
            'result_sha256' => $payload['result_sha256'],
            'outcome' => $payload['outcome'],
            'reason_codes' => $payload['reason_codes'],
            'advisory_codes' => $payload['advisory_codes'],
            'evidence' => $payload['evidence'],
            'correlation_id' => $event->correlation_id,
            'traceparent' => $event->traceparent,
            'evaluated_at' => $event->occurred_at,
            'event_received_at' => $event->received_at,
            'projected_at' => now(),
            ...$overrides,
        ]);
    }

    private function markProjected(TradingEngineEvent $event): void
    {
        $event->forceFill([
            'handling_status' => TradingEngineEvent::STATUS_PROJECTED,
            'handled_at' => now(),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function assertIssue(array $result, string $code, string $eventId): void
    {
        $this->assertContains([
            'code' => $code,
            'event_id' => $eventId,
        ], array_map(
            fn (array $issue): array => [
                'code' => $issue['code'],
                'event_id' => $issue['event_id'],
            ],
            $result['issues'],
        ));
    }

    /** @return array<string, mixed> */
    private function recordedEvent(
        TradeOpportunity $opportunity,
        string $eventId = self::RECORDED_EVENT_ID,
    ): array {
        $payload = [
            'operation_id' => '01K9ZYXWVTSRQPNMKJHGFEDCBF',
            'opportunity_id' => self::ENGINE_OPPORTUNITY_ID,
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
                'volume_usd' => ['value' => '800', 'provider' => 'birdeye', 'window' => '1m'],
            ],
            'qualification' => [
                'qualified_at' => '2026-09-29T12:00:00.000Z',
                'discovery_market_cap_usd' => '10000',
                'move_since_discovery_percent' => '20',
            ],
        ];

        return $this->envelope([
            'event_id' => $eventId,
            'event_type' => 'opportunity.recorded.v1',
            'aggregate_type' => 'opportunity',
            'aggregate_id' => self::ENGINE_OPPORTUNITY_ID,
            'causation_id' => $payload['operation_id'],
            'idempotency_key' => 'opportunity:record:laravel:'.$opportunity->getKey().':v1',
            'payload' => $payload,
        ]);
    }

    /** @return array<string, mixed> */
    private function evaluatedEvent(string $causationId = self::RECORDED_EVENT_ID): array
    {
        $payload = [
            'evaluation_id' => self::EVALUATION_ID,
            'opportunity_id' => self::ENGINE_OPPORTUNITY_ID,
            'policy' => [
                'key' => 'migration-opportunity-snapshot',
                'version' => 1,
                'algorithm_key' => 'threshold-matrix',
                'algorithm_version' => 1,
                'definition_sha256' => str_repeat('a', 64),
            ],
            'source' => [
                'request_sha256' => str_repeat('b', 64),
                'evaluation_input_sha256' => str_repeat('c', 64),
            ],
            'outcome' => 'passed',
            'reason_codes' => [],
            'advisory_codes' => ['SECURITY_EVIDENCE_UNAVAILABLE'],
            'evidence' => [
                'profile' => 'solana:new-token',
                'facts' => ['market_cap_usd' => '12000'],
                'checks' => [['check' => 'market_cap', 'status' => 'passed']],
            ],
            'result_sha256' => str_repeat('d', 64),
        ];

        return $this->envelope([
            'event_id' => self::EVALUATION_EVENT_ID,
            'event_type' => 'opportunity.evaluated.v1',
            'aggregate_type' => 'opportunity_evaluation',
            'aggregate_id' => self::EVALUATION_ID,
            'causation_id' => $causationId,
            'idempotency_key' => 'evaluation:'.self::ENGINE_OPPORTUNITY_ID.':migration-opportunity-snapshot:1',
            'payload' => $payload,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function envelope(array $overrides): array
    {
        return [
            'event_id' => $overrides['event_id'],
            'event_type' => $overrides['event_type'],
            'schema_version' => 1,
            'occurred_at' => '2026-09-29T12:00:00.000Z',
            'producer' => 'trading-engine',
            'aggregate_type' => $overrides['aggregate_type'],
            'aggregate_id' => $overrides['aggregate_id'],
            'aggregate_version' => 1,
            'correlation_id' => 'opportunity-correlation',
            'causation_id' => $overrides['causation_id'],
            'idempotency_key' => $overrides['idempotency_key'],
            'traceparent' => self::TRACEPARENT,
            'payload' => $overrides['payload'],
            'payload_sha256' => hash('sha256', $this->encode($overrides['payload'])),
        ];
    }

    /** @param array<string, mixed> $event */
    private function storeEvent(array $event): TradingEngineEvent
    {
        $rawBody = $this->encode($event);

        return TradingEngineEvent::query()->create([
            ...$event,
            'raw_body_sha256' => hash('sha256', $rawBody),
            'event_envelope' => $event,
            'handling_status' => TradingEngineEvent::STATUS_STORED,
            'handling_attempts' => 0,
            'received_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function auditState(): array
    {
        return [
            'events' => DB::table('trading_engine_event_inbox')->orderBy('id')->get()
                ->map(fn (object $row): array => (array) $row)
                ->all(),
            'links' => DB::table('trading_engine_opportunity_links')->orderBy('id')->get()
                ->map(fn (object $row): array => (array) $row)
                ->all(),
            'evaluations' => DB::table('trading_engine_opportunity_evaluations')->orderBy('id')->get()
                ->map(fn (object $row): array => (array) $row)
                ->all(),
        ];
    }

    /** @param array<string, mixed> $value */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function eventId(int $number): string
    {
        return '01KB'.str_pad((string) $number, 22, '0', STR_PAD_LEFT);
    }
}
