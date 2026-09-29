<?php

namespace Tests\Feature;

use App\Chain;
use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Enums\TradeOpportunityStatus;
use App\Exceptions\TradingEngineProjectionException;
use App\Jobs\ProjectTradingEngineEvent;
use App\Models\TradeOpportunity;
use App\Models\TradingEngineEvent;
use App\Models\TradingEngineOpportunityEvaluation;
use App\Models\User;
use App\Services\TradingEngine\TradingEngineOpportunityProjector;
use App\Services\UserTelegramNotificationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TradingEngineOpportunityProjectionTest extends TestCase
{
    use RefreshDatabase;

    private const ENGINE_OPPORTUNITY_ID = '01K9ABCDEFGHJKMNPQRSTVWXYZ';

    private const EVALUATION_EVENT_ID = '01K9ZYXWVTSRQPNMKJHGFEDCBC';

    private const EVALUATION_ID = '01K9ZYXWVTSRQPNMKJHGFEDCBA';

    private const PATH = '/internal/trading-engine/events';

    private const RECORDED_EVENT_ID = '01K9ZYXWVTSRQPNMKJHGFEDCBB';

    private const SECRET = 'test-only-webhook-secret-32-bytes-long';

    private const TRACEPARENT = '00-0123456789abcdef0123456789abcdef-0123456789abcdef-01';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-28 12:00:00');
        config()->set([
            'services.trading_engine.enabled' => true,
            'services.trading_engine.opportunity_export_enabled' => false,
            'services.trading_engine.opportunity_projection_enabled' => true,
            'services.trading_engine.webhook_secret' => self::SECRET,
            'services.trading_engine.webhook_timestamp_tolerance_seconds' => 60,
            'services.trading_engine.webhook_body_max_bytes' => 262144,
            'services.trading_engine.webhook_rate_limit_per_minute' => 600,
        ]);
    }

    public function test_recorded_event_creates_one_exact_correlation_link_and_duplicate_processing_is_a_noop(): void
    {
        $opportunity = $this->opportunity();
        $recorded = $this->storeEvent($this->recordedEvent($opportunity));
        $projector = app(TradingEngineOpportunityProjector::class);

        $first = $projector->project($recorded->event_id);
        $second = $projector->project($recorded->event_id);

        $this->assertSame(TradingEngineEvent::STATUS_PROJECTED, $first['status']);
        $this->assertSame(TradingEngineEvent::STATUS_PROJECTED, $second['status']);
        $this->assertDatabaseCount('trading_engine_opportunity_links', 1);
        $this->assertDatabaseHas('trading_engine_opportunity_links', [
            'trade_opportunity_id' => $opportunity->getKey(),
            'engine_opportunity_id' => self::ENGINE_OPPORTUNITY_ID,
            'recorded_event_id' => self::RECORDED_EVENT_ID,
            'user_id' => $opportunity->user_id,
            'discovery_key' => $opportunity->discovery_key,
            'scanner' => 'new-token',
            'network_id' => 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp',
            'asset_address' => $opportunity->address,
        ]);
        $this->assertSame(1, $recorded->fresh()->handling_attempts);
        $this->assertNotNull($recorded->fresh()->handled_at);
    }

    public function test_evaluation_after_recorded_event_projects_once_without_business_or_execution_side_effects(): void
    {
        Notification::fake();
        $this->mock(UserTelegramNotificationService::class)->shouldNotReceive('send');
        $opportunity = $this->opportunity();
        $original = $opportunity->fresh()->getAttributes();
        $recorded = $this->storeEvent($this->recordedEvent($opportunity));
        $evaluation = $this->storeEvent($this->evaluatedEvent());
        $projector = app(TradingEngineOpportunityProjector::class);

        $projector->project($recorded->event_id);
        $result = $projector->project($evaluation->event_id);
        $projection = TradingEngineOpportunityEvaluation::query()->sole();

        $this->assertSame(TradingEngineEvent::STATUS_PROJECTED, $result['status']);
        $this->assertSame(self::EVALUATION_ID, $projection->evaluation_id);
        $this->assertSame(self::ENGINE_OPPORTUNITY_ID, $projection->engine_opportunity_id);
        $this->assertSame(self::RECORDED_EVENT_ID, $projection->recorded_event_id);
        $this->assertSame(self::EVALUATION_EVENT_ID, $projection->evaluation_event_id);
        $this->assertSame('migration-opportunity-snapshot', $projection->policy_key);
        $this->assertSame(1, $projection->policy_version);
        $this->assertSame('threshold-matrix', $projection->algorithm_key);
        $this->assertSame(1, $projection->algorithm_version);
        $this->assertSame('passed', $projection->outcome);
        $this->assertSame([], $projection->reason_codes);
        $this->assertSame(['SECURITY_EVIDENCE_UNAVAILABLE'], $projection->advisory_codes);
        $this->assertSame($this->evaluationPayload()['evidence'], $projection->evidence);
        $this->assertSame($original, $opportunity->fresh()->getAttributes());
        $this->assertDatabaseCount('trading_engine_opportunity_evaluations', 1);
        $this->assertDatabaseCount('paper_wallets', 0);
        $this->assertDatabaseCount('paper_positions', 0);
        $this->assertDatabaseCount('solana_swap_attempts', 0);
        $this->assertDatabaseCount('ethereum_swap_attempts', 0);
        $this->assertDatabaseCount('live_positions', 0);
        $this->assertDatabaseCount('connected_wallets', 0);
        Notification::assertNothingSent();
    }

    public function test_evaluation_before_recorded_event_is_deferred_and_projects_when_recorded_event_becomes_available(): void
    {
        $opportunity = $this->opportunity();
        $evaluation = $this->storeEvent($this->evaluatedEvent());
        $projector = app(TradingEngineOpportunityProjector::class);

        $first = $projector->project($evaluation->event_id);

        $this->assertSame(TradingEngineEvent::STATUS_DEFERRED, $first['status']);
        $this->assertSame('CAUSAL_RECORDED_EVENT_PENDING', $evaluation->fresh()->handling_error_code);
        $this->assertDatabaseCount('trading_engine_opportunity_evaluations', 0);

        $recorded = $this->storeEvent($this->recordedEvent($opportunity));
        (new ProjectTradingEngineEvent($recorded->event_id))->handle($projector);

        $this->assertSame(TradingEngineEvent::STATUS_PROJECTED, $recorded->fresh()->handling_status);
        $this->assertSame(TradingEngineEvent::STATUS_PROJECTED, $evaluation->fresh()->handling_status);
        $this->assertDatabaseCount('trading_engine_opportunity_links', 1);
        $this->assertDatabaseCount('trading_engine_opportunity_evaluations', 1);
    }

    public function test_evaluation_with_non_recorded_causal_event_fails_closed(): void
    {
        $causal = $this->recordedEvent($this->opportunity());
        $causal['event_type'] = 'foundation.noop_accepted.v1';
        $this->storeEvent($causal);
        $evaluation = $this->storeEvent($this->evaluatedEvent());
        $projector = app(TradingEngineOpportunityProjector::class);

        $result = $projector->project($evaluation->event_id);

        $this->assertSame(TradingEngineEvent::STATUS_FAILED, $result['status']);
        $this->assertSame('CAUSAL_EVENT_TYPE_MISMATCH', $evaluation->fresh()->handling_error_code);
        $this->assertDatabaseCount('trading_engine_opportunity_evaluations', 0);
    }

    public function test_evaluation_with_different_engine_opportunity_fails_closed(): void
    {
        $opportunity = $this->opportunity();
        $recorded = $this->storeEvent($this->recordedEvent($opportunity));
        $projector = app(TradingEngineOpportunityProjector::class);
        $projector->project($recorded->event_id);
        $event = $this->evaluatedEvent();
        $event['payload']['opportunity_id'] = '01K9ZYXWVTSRQPNMKJHGFEDCBD';
        $evaluation = $this->storeEvent($event);

        $result = $projector->project($evaluation->event_id);

        $this->assertSame(TradingEngineEvent::STATUS_FAILED, $result['status']);
        $this->assertSame('ENGINE_OPPORTUNITY_MISMATCH', $evaluation->fresh()->handling_error_code);
        $this->assertDatabaseCount('trading_engine_opportunity_evaluations', 0);
    }

    /**
     * @param  array<string, mixed>  $change
     */
    #[DataProvider('localIdentityMismatches')]
    public function test_recorded_event_with_laravel_identity_mismatch_fails_closed(array $change): void
    {
        $opportunity = $this->opportunity();
        $event = $this->recordedEvent($opportunity);
        data_set($event, $change['path'], $change['value']);
        $recorded = $this->storeEvent($event);

        $result = app(TradingEngineOpportunityProjector::class)->project($recorded->event_id);

        $this->assertSame(TradingEngineEvent::STATUS_FAILED, $result['status']);
        $this->assertSame('LOCAL_OPPORTUNITY_IDENTITY_MISMATCH', $recorded->fresh()->handling_error_code);
        $this->assertDatabaseCount('trading_engine_opportunity_links', 0);
    }

    /** @return array<string, array{array{path: string, value: mixed}}> */
    public static function localIdentityMismatches(): array
    {
        return [
            'user' => [['path' => 'payload.subject.control_plane_user_id', 'value' => '999']],
            'discovery' => [['path' => 'payload.source.discovery_key', 'value' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb']],
            'scanner' => [['path' => 'payload.source.scanner', 'value' => 'momentum']],
            'network' => [['path' => 'payload.network.id', 'value' => 'eip155:1']],
            'address' => [['path' => 'payload.asset.address', 'value' => '11111111111111111111111111111111']],
        ];
    }

    public function test_duplicate_jobs_leave_one_link_and_one_projection(): void
    {
        $opportunity = $this->opportunity();
        $recorded = $this->storeEvent($this->recordedEvent($opportunity));
        $evaluation = $this->storeEvent($this->evaluatedEvent());
        $projector = app(TradingEngineOpportunityProjector::class);
        $recordedJob = new ProjectTradingEngineEvent($recorded->event_id);
        $evaluationJob = new ProjectTradingEngineEvent($evaluation->event_id);

        $recordedJob->handle($projector);
        $recordedJob->handle($projector);
        $evaluationJob->handle($projector);
        $evaluationJob->handle($projector);

        $this->assertDatabaseCount('trading_engine_opportunity_links', 1);
        $this->assertDatabaseCount('trading_engine_opportunity_evaluations', 1);
        $this->assertSame(1, $recorded->fresh()->handling_attempts);
        $this->assertSame(1, $evaluation->fresh()->handling_attempts);
    }

    public function test_future_policy_versions_coexist_without_overwriting_history(): void
    {
        $opportunity = $this->opportunity();
        $recorded = $this->storeEvent($this->recordedEvent($opportunity));
        $projector = app(TradingEngineOpportunityProjector::class);
        $projector->project($recorded->event_id);
        $first = $this->storeEvent($this->evaluatedEvent());
        $secondEvent = $this->evaluatedEvent(2);
        $secondEvent['event_id'] = '01K9ZYXWVTSRQPNMKJHGFEDCBD';
        $secondEvent['aggregate_id'] = '01K9ZYXWVTSRQPNMKJHGFEDCBE';
        $secondEvent['payload']['evaluation_id'] = '01K9ZYXWVTSRQPNMKJHGFEDCBE';
        $second = $this->storeEvent($secondEvent);

        $projector->project($first->event_id);
        $projector->project($second->event_id);

        $this->assertSame([1, 2], TradingEngineOpportunityEvaluation::query()
            ->orderBy('policy_version')
            ->pluck('policy_version')
            ->all());
        $this->assertDatabaseCount('trading_engine_opportunity_evaluations', 2);
    }

    public function test_projection_transaction_failure_leaves_authenticated_inbox_recoverable(): void
    {
        $opportunity = $this->opportunity();
        $recorded = $this->storeEvent($this->recordedEvent($opportunity));
        $evaluation = $this->storeEvent($this->evaluatedEvent());
        $projector = app(TradingEngineOpportunityProjector::class);
        $projector->project($recorded->event_id);
        DB::statement(<<<'SQL'
            CREATE TRIGGER test_projection_state_failure
            BEFORE UPDATE OF handling_status ON trading_engine_event_inbox
            WHEN OLD.event_id = '01K9ZYXWVTSRQPNMKJHGFEDCBC' AND NEW.handling_status = 'projected'
            BEGIN
                SELECT RAISE(ABORT, 'forced processing-state failure');
            END
            SQL);

        try {
            $projector->project($evaluation->event_id);
            $this->fail('The forced projection failure did not throw.');
        } catch (TradingEngineProjectionException $exception) {
            $this->assertTrue($exception->retryable);
            $this->assertSame('PROJECTION_TEMPORARY_FAILURE', $exception->errorCode);
        } finally {
            DB::statement('DROP TRIGGER IF EXISTS test_projection_state_failure');
        }

        $recoverable = $evaluation->fresh();
        $this->assertSame(TradingEngineEvent::STATUS_RETRYABLE, $recoverable->handling_status);
        $this->assertSame('PROJECTION_TEMPORARY_FAILURE', $recoverable->handling_error_code);
        $this->assertSame($evaluation->payload, $recoverable->payload);
        $this->assertDatabaseCount('trading_engine_opportunity_evaluations', 0);

        $projector->project($evaluation->event_id);

        $this->assertSame(TradingEngineEvent::STATUS_PROJECTED, $evaluation->fresh()->handling_status);
        $this->assertDatabaseCount('trading_engine_opportunity_evaluations', 1);
    }

    public function test_webhook_acknowledgement_survives_projection_failure(): void
    {
        config()->set('queue.default', 'sync');
        $opportunity = $this->opportunity();
        $event = $this->recordedEvent($opportunity);
        $projector = $this->mock(TradingEngineOpportunityProjector::class);
        $projector->shouldReceive('project')
            ->once()
            ->andThrow(new TradingEngineProjectionException(
                'PROJECTION_TEMPORARY_FAILURE',
                'The trading engine event projection temporarily failed.',
                true,
            ));
        $projector->shouldReceive('markDispatchFailure')->once();

        $this->sendSigned($event)->assertAccepted();

        $this->assertDatabaseHas('trading_engine_event_inbox', [
            'event_id' => self::RECORDED_EVENT_ID,
            'handling_status' => TradingEngineEvent::STATUS_STORED,
        ]);
        $this->assertDatabaseCount('trading_engine_opportunity_links', 0);
    }

    public function test_disabled_projection_stores_event_without_dispatching_projector(): void
    {
        Queue::fake([ProjectTradingEngineEvent::class]);
        config()->set('services.trading_engine.opportunity_projection_enabled', false);
        $opportunity = $this->opportunity();

        $this->sendSigned($this->recordedEvent($opportunity))->assertAccepted();

        Queue::assertNothingPushed();
        $this->assertDatabaseHas('trading_engine_event_inbox', [
            'event_id' => self::RECORDED_EVENT_ID,
            'handling_status' => TradingEngineEvent::STATUS_STORED,
        ]);
        $this->assertDatabaseCount('trading_engine_opportunity_links', 0);
    }

    public function test_enabled_projection_dispatches_only_event_identity(): void
    {
        Queue::fake([ProjectTradingEngineEvent::class]);
        $opportunity = $this->opportunity();

        $this->sendSigned($this->recordedEvent($opportunity))->assertAccepted();

        Queue::assertPushed(ProjectTradingEngineEvent::class, function (ProjectTradingEngineEvent $job): bool {
            return $job->eventId === self::RECORDED_EVENT_ID;
        });
        $this->assertDatabaseCount('trading_engine_opportunity_links', 0);
    }

    public function test_projection_rows_are_append_only_at_the_database_boundary(): void
    {
        $opportunity = $this->opportunity();
        $recorded = $this->storeEvent($this->recordedEvent($opportunity));
        $evaluation = $this->storeEvent($this->evaluatedEvent());
        $projector = app(TradingEngineOpportunityProjector::class);
        $projector->project($recorded->event_id);
        $projector->project($evaluation->event_id);

        try {
            DB::table('trading_engine_opportunity_evaluations')
                ->where('evaluation_id', self::EVALUATION_ID)
                ->update(['outcome' => 'failed']);
            $this->fail('The append-only update was not rejected.');
        } catch (QueryException) {
        }

        try {
            DB::table('trading_engine_opportunity_evaluations')
                ->where('evaluation_id', self::EVALUATION_ID)
                ->delete();
            $this->fail('The append-only delete was not rejected.');
        } catch (QueryException) {
        }

        $this->assertSame('passed', TradingEngineOpportunityEvaluation::query()->sole()->outcome);
        $this->assertDatabaseCount('trading_engine_opportunity_evaluations', 1);
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

    /** @return array<string, mixed> */
    private function recordedEvent(TradeOpportunity $opportunity): array
    {
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
                'qualified_at' => '2026-09-28T12:00:00.000Z',
                'discovery_market_cap_usd' => '10000',
                'move_since_discovery_percent' => '20',
            ],
        ];

        return $this->envelope([
            'event_id' => self::RECORDED_EVENT_ID,
            'event_type' => 'opportunity.recorded.v1',
            'aggregate_type' => 'opportunity',
            'aggregate_id' => self::ENGINE_OPPORTUNITY_ID,
            'causation_id' => $payload['operation_id'],
            'idempotency_key' => 'opportunity:record:laravel:'.$opportunity->getKey().':v1',
            'payload' => $payload,
        ]);
    }

    /** @return array<string, mixed> */
    private function evaluatedEvent(int $policyVersion = 1): array
    {
        $payload = $this->evaluationPayload($policyVersion);

        return $this->envelope([
            'event_id' => self::EVALUATION_EVENT_ID,
            'event_type' => 'opportunity.evaluated.v1',
            'aggregate_type' => 'opportunity_evaluation',
            'aggregate_id' => $payload['evaluation_id'],
            'causation_id' => self::RECORDED_EVENT_ID,
            'idempotency_key' => 'evaluation:'.self::ENGINE_OPPORTUNITY_ID.':migration-opportunity-snapshot:'.$policyVersion,
            'payload' => $payload,
        ]);
    }

    /** @return array<string, mixed> */
    private function evaluationPayload(int $policyVersion = 1): array
    {
        return [
            'evaluation_id' => self::EVALUATION_ID,
            'opportunity_id' => self::ENGINE_OPPORTUNITY_ID,
            'policy' => [
                'key' => 'migration-opportunity-snapshot',
                'version' => $policyVersion,
                'algorithm_key' => 'threshold-matrix',
                'algorithm_version' => $policyVersion,
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
                'facts' => [
                    'market_cap_usd' => '12000',
                    'liquidity_usd' => null,
                    'volume_5m_usd' => null,
                    'move_since_discovery_percent' => '20',
                    'classification' => 'strong',
                    'pair_address' => null,
                    'pair_available' => null,
                    'requested_token_is_base' => null,
                    'security_status' => 'unavailable',
                    'security_provider' => null,
                    'security_passed' => null,
                ],
                'checks' => [
                    ['check' => 'market_cap', 'status' => 'passed'],
                    ['check' => 'liquidity', 'status' => 'indeterminate'],
                    ['check' => 'movement', 'status' => 'passed'],
                    ['check' => 'classification', 'status' => 'passed'],
                    ['check' => 'security', 'status' => 'passed'],
                ],
            ],
            'result_sha256' => str_repeat('d', 64),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function envelope(array $overrides): array
    {
        $payload = $overrides['payload'];

        return [
            'event_id' => $overrides['event_id'],
            'event_type' => $overrides['event_type'],
            'schema_version' => 1,
            'occurred_at' => '2026-09-28T12:00:00.000Z',
            'producer' => 'trading-engine',
            'aggregate_type' => $overrides['aggregate_type'],
            'aggregate_id' => $overrides['aggregate_id'],
            'aggregate_version' => 1,
            'correlation_id' => 'opportunity-correlation',
            'causation_id' => $overrides['causation_id'],
            'idempotency_key' => $overrides['idempotency_key'],
            'traceparent' => self::TRACEPARENT,
            'payload' => $payload,
            'payload_sha256' => hash('sha256', $this->encode($payload)),
        ];
    }

    /** @param  array<string, mixed>  $event */
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

    /** @param  array<string, mixed>  $event */
    private function sendSigned(array $event): TestResponse
    {
        $rawBody = $this->encode($event);
        $timestamp = (string) now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.POST.'.self::PATH.'.'.$rawBody, self::SECRET);

        return $this->call('POST', self::PATH, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ENGINE_TIMESTAMP' => $timestamp,
            'HTTP_X_ENGINE_SIGNATURE' => 'v1='.$signature,
            'HTTP_X_ENGINE_EVENT_ID' => $event['event_id'],
            'HTTP_X_CORRELATION_ID' => $event['correlation_id'],
            'HTTP_TRACEPARENT' => $event['traceparent'],
        ], $rawBody);
    }

    /** @param  array<string, mixed>  $value */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
