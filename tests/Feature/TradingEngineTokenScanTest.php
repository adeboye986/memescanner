<?php

namespace Tests\Feature;

use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Exceptions\TradingEngineProjectionException;
use App\Jobs\RunDashboardCommand;
use App\Jobs\SubmitTradingEngineTokenScan;
use App\Models\SystemActivity;
use App\Models\TradeOpportunity;
use App\Models\TradingEngineEvent;
use App\Models\TradingEngineScanRequest;
use App\Models\User;
use App\Models\UserTradingPreference;
use App\Services\TradingEngine\TradingEngineCanonicalJson;
use App\Services\TradingEngine\TradingEngineOpportunityProjector;
use App\Services\TradingEngine\TradingEngineScanDeliveryTracker;
use App\Services\TradingEngine\TradingEngineScanEventPayloadValidator;
use App\Services\TradingEngine\TradingEngineScanProjector;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\RefreshesPaperTradingDatabase;
use Tests\TestCase;

class TradingEngineTokenScanTest extends TestCase
{
    use RefreshesPaperTradingDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->refreshPaperTradingDatabase();
        $this->user = User::factory()->create(['is_admin' => false]);
        UserTradingPreference::factory()->create([
            'user_id' => $this->user,
            'execution_mode' => ExecutionMode::Paper,
            'entry_mode' => EntryMode::Signal,
            'trading_enabled' => true,
        ]);
        $this->actingAs($this->user);
        config([
            'services.trading_engine.enabled' => true,
            'services.trading_engine.scanner_integration_enabled' => true,
            'services.trading_engine.opportunity_projection_enabled' => true,
            'services.trading_engine.scanner_canary_user_ids' => (string) $this->user->getKey(),
            'queue.connections.database.driver' => 'database',
            'queue.connections.database.connection' => null,
        ]);
    }

    public function test_canary_scan_is_durably_queued_without_legacy_scanner_job(): void
    {
        Queue::fake([SubmitTradingEngineTokenScan::class, RunDashboardCommand::class]);

        $this->post(route('dashboard.actions.store', 'token-scan'), ['chain' => 'solana'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $activity = SystemActivity::query()->sole();
        $request = TradingEngineScanRequest::query()->sole();

        $this->assertSame($activity->getKey(), $request->system_activity_id);
        $this->assertSame(TradingEngineScanRequest::STATUS_QUEUED, $request->status);
        $this->assertSame('new-token', $request->scanner);
        $this->assertSame((string) $activity->getKey(), data_get($request->payload, 'source.activity_id'));
        Queue::assertPushed(SubmitTradingEngineTokenScan::class);
        Queue::assertNotPushed(RunDashboardCommand::class);
    }

    public function test_non_canary_scan_preserves_legacy_scanner_routing(): void
    {
        config(['services.trading_engine.scanner_canary_user_ids' => '999999']);
        Queue::fake([SubmitTradingEngineTokenScan::class, RunDashboardCommand::class]);

        $this->post(route('dashboard.actions.store', 'momentum-scan'), ['chain' => 'ethereum'])
            ->assertRedirect();

        $this->assertDatabaseCount('trading_engine_scan_requests', 0);
        Queue::assertPushed(RunDashboardCommand::class);
        Queue::assertNotPushed(SubmitTradingEngineTokenScan::class);
    }

    public function test_attempt_fencing_and_bounded_stale_recovery_are_idempotent(): void
    {
        Queue::fake([SubmitTradingEngineTokenScan::class]);
        $request = $this->queueEngineScan();
        $deliveries = app(TradingEngineScanDeliveryTracker::class);
        $attempt = $deliveries->beginAttempt($request);

        $this->assertSame(1, $attempt);
        $this->assertNull($deliveries->beginAttempt($request));
        $deliveries->acknowledged($request, [
            'operationId' => '01J00000000000000000000001',
            'scanExecutionId' => '01J00000000000000000000002',
            'status' => 'accepted',
            'duplicate' => false,
        ], $attempt);
        $deliveries->acceptanceUnknown($request, 'JOB_FAILED', $attempt);

        $this->assertSame(
            TradingEngineScanRequest::STATUS_ACCEPTED,
            $request->fresh()->status,
        );

        $stale = $this->queueEngineScan('momentum-scan', 'ethereum');
        $stale->forceFill([
            'status' => TradingEngineScanRequest::STATUS_SUBMITTING,
            'attempt_count' => 1,
            'submitting_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ])->save();
        Queue::fake([SubmitTradingEngineTokenScan::class]);

        $this->assertSame(1, $deliveries->recover());
        Queue::assertPushed(
            SubmitTradingEngineTokenScan::class,
            fn (SubmitTradingEngineTokenScan $job): bool => $job->scanRequestId === $stale->getKey(),
        );
    }

    public function test_terminal_event_supersedes_ambiguous_delivery_and_duplicate_projection(): void
    {
        Queue::fake([SubmitTradingEngineTokenScan::class]);
        $request = $this->queueEngineScan();
        $request->forceFill([
            'status' => TradingEngineScanRequest::STATUS_ACCEPTANCE_UNKNOWN,
            'last_error_code' => 'TRANSPORT_TIMEOUT',
            'acceptance_unknown_at' => now(),
        ])->save();
        $scanId = '01J00000000000000000000003';
        $payload = [
            'scan_execution_id' => $scanId,
            'source' => data_get($request->payload, 'source'),
            'subject' => data_get($request->payload, 'subject'),
            'scanner' => $request->scanner,
            'network' => data_get($request->payload, 'network'),
            'outcome' => 'succeeded_with_warnings',
            'counts' => [
                'discovered' => 5,
                'examined' => 5,
                'qualified' => 2,
                'opportunities_created' => 1,
                'opportunities_existing' => 1,
                'skipped' => 2,
                'failed' => 1,
            ],
            'provider_failures' => ['SECURITY_PROVIDER_UNAVAILABLE'],
            'error_code' => null,
            'completed_at' => now()->toIso8601String(),
        ];
        $validator = app(TradingEngineScanEventPayloadValidator::class);
        $invalidCounts = $payload;
        $invalidCounts['counts']['qualified'] = 6;
        $this->assertTrue($validator->isValid($payload));
        $this->assertFalse($validator->isValid($invalidCounts));
        $event = TradingEngineEvent::query()->create([
            'event_id' => '01J00000000000000000000004',
            'event_type' => 'token.scan.completed.v1',
            'schema_version' => 1,
            'occurred_at' => now(),
            'producer' => 'trading-engine',
            'aggregate_type' => 'token_scan_execution',
            'aggregate_id' => $scanId,
            'aggregate_version' => 1,
            'correlation_id' => 'scanner-test',
            'causation_id' => $scanId,
            'idempotency_key' => 'token-scan:'.$scanId.':terminal:v1',
            'traceparent' => '00-11111111111111111111111111111111-2222222222222222-01',
            'payload_sha256' => str_repeat('a', 64),
            'raw_body_sha256' => str_repeat('b', 64),
            'event_envelope' => ['event_id' => '01J00000000000000000000004'],
            'payload' => $payload,
            'handling_status' => TradingEngineEvent::STATUS_STORED,
            'handling_attempts' => 0,
            'received_at' => now(),
        ]);
        $projector = app(TradingEngineScanProjector::class);

        $this->assertTrue($projector->project($event->event_id));
        $this->assertTrue($projector->project($event->event_id));
        $request->refresh();
        $activity = $request->activity()->firstOrFail();

        $this->assertSame(TradingEngineScanRequest::STATUS_PROJECTED, $request->status);
        $this->assertSame($scanId, $request->engine_scan_execution_id);
        $this->assertNull($request->acceptance_unknown_at);
        $this->assertSame('completed', $activity->status);
        $this->assertSame(2, data_get($activity->result_summary, 'counts.qualified'));
        $this->assertSame(1, data_get($activity->result_summary, 'counts.opportunities_created'));

        app(TradingEngineScanDeliveryTracker::class)
            ->acceptanceUnknown($request, 'JOB_FAILED', 1);
        $this->assertSame(TradingEngineScanRequest::STATUS_PROJECTED, $request->fresh()->status);
    }

    public function test_out_of_order_opportunity_waits_for_authoritative_scan_identity(): void
    {
        Queue::fake([SubmitTradingEngineTokenScan::class]);
        $owningRequest = $this->queueEngineScan();
        $otherActivity = $owningRequest->activity()->firstOrFail()->replicate();
        $otherActivity->forceFill([
            'status' => 'pending',
            'started_at' => null,
            'finished_at' => null,
            'duration_seconds' => null,
            'exit_code' => null,
            'output' => null,
            'error_message' => null,
        ])->save();
        $otherPayload = $owningRequest->payload;
        data_set($otherPayload, 'source.activity_id', (string) $otherActivity->getKey());
        $otherRequest = TradingEngineScanRequest::query()->create([
            'system_activity_id' => $otherActivity->getKey(),
            'user_id' => $owningRequest->user_id,
            'scanner' => $owningRequest->scanner,
            'network_id' => $owningRequest->network_id,
            'entry_mode' => $owningRequest->entry_mode,
            'idempotency_key' => 'token-scan:laravel:'.$otherActivity->getKey().':v1',
            'payload_sha256' => app(TradingEngineCanonicalJson::class)
                ->hash($otherPayload),
            'payload' => $otherPayload,
            'status' => TradingEngineScanRequest::STATUS_QUEUED,
            'queued_at' => now(),
        ]);
        $scanId = '01J0000000000000000000000B';
        $discoveryId = '01J0000000000000000000000C';
        $engineOpportunityId = '01J0000000000000000000000D';
        $operationId = '01J0000000000000000000000E';
        $payload = [
            'operation_id' => $operationId,
            'opportunity_id' => $engineOpportunityId,
            'schema_version' => 1,
            'source' => [
                'system' => 'meme-scanner-trading-engine',
                'opportunity_id' => $discoveryId,
                'discovery_key' => str_repeat('e', 64),
                'scanner' => 'new-token',
                'scan_execution_id' => $scanId,
            ],
            'subject' => ['control_plane_user_id' => (string) $this->user->getKey()],
            'network' => ['id' => 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp'],
            'asset' => [
                'address' => '11111111111111111111111111111111',
                'symbol' => 'ORDER',
                'name' => 'Out-of-order token',
            ],
            'market_snapshot' => [
                'price_usd' => ['value' => '0.001', 'provider' => 'birdeye'],
                'market_cap_usd' => ['value' => '10000', 'provider' => 'birdeye'],
                'liquidity_usd' => ['value' => '5000', 'provider' => 'birdeye'],
                'volume_usd' => ['value' => '1000', 'provider' => 'birdeye', 'window' => '1m'],
            ],
            'qualification' => [
                'qualified_at' => now()->toIso8601String(),
                'discovery_market_cap_usd' => '10000',
                'classification' => 'strong',
            ],
        ];
        $event = TradingEngineEvent::query()->create([
            'event_id' => '01J0000000000000000000000F',
            'event_type' => 'opportunity.recorded.v1',
            'schema_version' => 1,
            'occurred_at' => now(),
            'producer' => 'trading-engine',
            'aggregate_type' => 'opportunity',
            'aggregate_id' => $engineOpportunityId,
            'aggregate_version' => 1,
            'correlation_id' => 'scanner-out-of-order-test',
            'causation_id' => $operationId,
            'idempotency_key' => 'opportunity:record:scanner:'.$discoveryId.':v1',
            'traceparent' => '00-11111111111111111111111111111111-2222222222222222-01',
            'payload_sha256' => str_repeat('e', 64),
            'raw_body_sha256' => str_repeat('f', 64),
            'event_envelope' => ['event_id' => '01J0000000000000000000000F'],
            'payload' => $payload,
            'handling_status' => TradingEngineEvent::STATUS_STORED,
            'handling_attempts' => 0,
            'received_at' => now(),
        ]);
        $projector = app(TradingEngineOpportunityProjector::class);

        try {
            $projector->project($event->event_id);
            $this->fail('The opportunity must wait until a unique scan request owns the engine execution.');
        } catch (TradingEngineProjectionException $exception) {
            $this->assertTrue($exception->retryable);
            $this->assertSame('SCAN_REQUEST_IDENTITY_PENDING', $exception->errorCode);
        }

        $this->assertSame(TradingEngineEvent::STATUS_RETRYABLE, $event->fresh()->handling_status);
        $this->assertDatabaseCount('trade_opportunities', 0);

        $owningRequest->forceFill([
            'status' => TradingEngineScanRequest::STATUS_ACCEPTED,
            'engine_operation_id' => '01J0000000000000000000000G',
            'engine_scan_execution_id' => $scanId,
            'accepted_at' => now(),
        ])->save();

        $result = $projector->project($event->event_id);
        $opportunity = TradeOpportunity::query()->sole();

        $this->assertSame(TradingEngineEvent::STATUS_PROJECTED, $result['status']);
        $this->assertSame($owningRequest->system_activity_id, $opportunity->systemActivities()->sole()->getKey());
        $this->assertNull($otherRequest->fresh()->engine_scan_execution_id);
    }

    public function test_engine_origin_opportunity_projects_to_the_owning_scan_without_legacy_execution(): void
    {
        Queue::fake([SubmitTradingEngineTokenScan::class]);
        $request = $this->queueEngineScan();
        $scanId = '01J00000000000000000000005';
        $request->forceFill([
            'status' => TradingEngineScanRequest::STATUS_ACCEPTED,
            'engine_operation_id' => '01J00000000000000000000006',
            'engine_scan_execution_id' => $scanId,
            'accepted_at' => now(),
        ])->save();
        $discoveryId = '01J00000000000000000000007';
        $engineOpportunityId = '01J00000000000000000000008';
        $operationId = '01J00000000000000000000009';
        $payload = [
            'operation_id' => $operationId,
            'opportunity_id' => $engineOpportunityId,
            'schema_version' => 1,
            'source' => [
                'system' => 'meme-scanner-trading-engine',
                'opportunity_id' => $discoveryId,
                'discovery_key' => str_repeat('a', 64),
                'scanner' => 'new-token',
                'scan_execution_id' => $scanId,
            ],
            'subject' => ['control_plane_user_id' => (string) $this->user->getKey()],
            'network' => ['id' => 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp'],
            'asset' => [
                'address' => '11111111111111111111111111111111',
                'symbol' => 'SCAN',
                'name' => 'Scanner token',
            ],
            'market_snapshot' => [
                'price_usd' => ['value' => '0.001', 'provider' => 'birdeye'],
                'market_cap_usd' => ['value' => '10000', 'provider' => 'birdeye'],
                'liquidity_usd' => ['value' => '5000', 'provider' => 'birdeye'],
                'volume_usd' => ['value' => '1000', 'provider' => 'birdeye', 'window' => '1m'],
            ],
            'qualification' => [
                'qualified_at' => now()->toIso8601String(),
                'discovery_market_cap_usd' => '10000',
                'classification' => 'strong',
            ],
        ];
        $event = TradingEngineEvent::query()->create([
            'event_id' => '01J0000000000000000000000A',
            'event_type' => 'opportunity.recorded.v1',
            'schema_version' => 1,
            'occurred_at' => now(),
            'producer' => 'trading-engine',
            'aggregate_type' => 'opportunity',
            'aggregate_id' => $engineOpportunityId,
            'aggregate_version' => 1,
            'correlation_id' => 'scanner-opportunity-test',
            'causation_id' => $operationId,
            'idempotency_key' => 'opportunity:record:scanner:'.$discoveryId.':v1',
            'traceparent' => '00-11111111111111111111111111111111-2222222222222222-01',
            'payload_sha256' => str_repeat('c', 64),
            'raw_body_sha256' => str_repeat('d', 64),
            'event_envelope' => ['event_id' => '01J0000000000000000000000A'],
            'payload' => $payload,
            'handling_status' => TradingEngineEvent::STATUS_STORED,
            'handling_attempts' => 0,
            'received_at' => now(),
        ]);

        $result = app(TradingEngineOpportunityProjector::class)->project($event->event_id);
        $opportunity = TradeOpportunity::query()->sole();

        $this->assertSame(TradingEngineEvent::STATUS_PROJECTED, $result['status']);
        $this->assertSame(ExecutionMode::Paper, $opportunity->execution_mode);
        $this->assertSame($this->user->getKey(), $opportunity->user_id);
        $this->assertSame($request->system_activity_id, $opportunity->systemActivities()->sole()->getKey());
        $this->assertSame($engineOpportunityId, $opportunity->engineOpportunityLink?->engine_opportunity_id);
        $this->assertDatabaseCount('paper_positions', 0);
    }

    private function queueEngineScan(
        string $action = 'token-scan',
        string $chain = 'solana',
    ): TradingEngineScanRequest {
        $this->post(route('dashboard.actions.store', $action), ['chain' => $chain])
            ->assertRedirect();

        return TradingEngineScanRequest::query()->latest('id')->firstOrFail();
    }
}
