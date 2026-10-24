<?php

namespace Tests\Feature;

use App\Exceptions\TradingEngineException;
use App\Jobs\SubmitTradingEngineOpportunity;
use App\Models\TradeOpportunity;
use App\Models\TradingEngineOpportunityExport;
use App\Models\User;
use App\Services\ApplicationSettingsService;
use App\Services\TradeOpportunityService;
use App\Services\TradingEngine\TradingEngineClient;
use App\Services\TradingEngine\TradingEngineOpportunityCommandFactory;
use App\Services\TradingEngine\TradingEngineOpportunityExportTracker;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\Concerns\RefreshesPaperTradingDatabase;
use Tests\TestCase;

class StageBOpportunityExportStateTest extends TestCase
{
    use RefreshesPaperTradingDatabase;

    private QueueManager $realQueue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->realQueue = app('queue');
        $this->refreshPaperTradingDatabase();
        app(ApplicationSettingsService::class)->update(['trading.entry_mode' => 'signal']);
        config()->set([
            'services.trading_engine.enabled' => true,
            'services.trading_engine.opportunity_export_enabled' => true,
        ]);
        Queue::fake([SubmitTradingEngineOpportunity::class]);
    }

    public function test_retry_and_acknowledgement_transitions_are_durable_and_idempotent(): void
    {
        [$opportunity, $command] = $this->queuedOpportunity();
        $tracker = app(TradingEngineOpportunityExportTracker::class);
        $job = new SubmitTradingEngineOpportunity($command['payload'], $command['idempotency_key']);
        $retryingClient = Mockery::mock(TradingEngineClient::class);
        $retryingClient->shouldReceive('recordOpportunity')
            ->once()
            ->with($command['idempotency_key'], $command['payload'])
            ->andThrow(new TradingEngineException('ENGINE_UNAVAILABLE', 'provider credentials are secret', true, 503));

        try {
            $job->handle($retryingClient, $tracker);
            $this->fail('Expected the retryable engine failure to be rethrown.');
        } catch (TradingEngineException $exception) {
            $this->assertSame('ENGINE_UNAVAILABLE', $exception->errorCode);
        }

        $export = $opportunity->engineOpportunityExport()->sole();
        $this->assertSame(TradingEngineOpportunityExport::STATUS_RETRYING, $export->status);
        $this->assertSame(1, $export->attempt_count);
        $this->assertSame('ENGINE_UNAVAILABLE', $export->last_error_code);
        $this->assertStringNotContainsString('credentials', json_encode($export->toArray(), JSON_THROW_ON_ERROR));

        $acknowledgingClient = Mockery::mock(TradingEngineClient::class);
        $acknowledgingClient->shouldReceive('recordOpportunity')
            ->once()
            ->with($command['idempotency_key'], $command['payload'])
            ->andReturnUsing(function (): array {
                $this->assertSame(0, DB::transactionLevel());

                return $this->acknowledgement();
            });

        $job->handle($acknowledgingClient, $tracker);

        $export->refresh();
        $this->assertSame(TradingEngineOpportunityExport::STATUS_ACKNOWLEDGED, $export->status);
        $this->assertSame(2, $export->attempt_count);
        $this->assertSame('01K9ABCDEFGHJKMNPQRSTVWXYZ', $export->engine_operation_id);
        $this->assertNotNull($export->acknowledged_at);

        $duplicateDeliveryClient = Mockery::mock(TradingEngineClient::class);
        $duplicateDeliveryClient->shouldNotReceive('recordOpportunity');
        $job->handle($duplicateDeliveryClient, $tracker);
        $this->assertSame(2, $export->fresh()->attempt_count);

        $sameExport = $tracker->queued($opportunity, $command['idempotency_key']);
        $this->assertTrue($sameExport->is($export));
        $this->assertDatabaseCount('trading_engine_opportunity_exports', 1);
    }

    public function test_non_retryable_failure_becomes_permanent_without_raw_exception_storage(): void
    {
        [$opportunity, $command] = $this->queuedOpportunity();
        $client = Mockery::mock(TradingEngineClient::class);
        $client->shouldReceive('recordOpportunity')
            ->once()
            ->andThrow(new TradingEngineException('VALIDATION_FAILED', 'secret response body', false, 422));

        (new SubmitTradingEngineOpportunity($command['payload'], $command['idempotency_key']))
            ->handle($client, app(TradingEngineOpportunityExportTracker::class));

        $export = $opportunity->engineOpportunityExport()->sole();

        $this->assertSame(TradingEngineOpportunityExport::STATUS_PERMANENTLY_FAILED, $export->status);
        $this->assertSame('VALIDATION_FAILED', $export->last_error_code);
        $this->assertNotNull($export->permanently_failed_at);
        $this->assertStringNotContainsString('secret response body', json_encode($export->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_existing_serialized_job_constructor_can_create_missing_tracking_record(): void
    {
        [$opportunity, $command] = $this->queuedOpportunity();
        $opportunity->engineOpportunityExport()->delete();
        $legacyQueuedJob = new SubmitTradingEngineOpportunity(
            $command['payload'],
            $command['idempotency_key'],
        );
        $client = Mockery::mock(TradingEngineClient::class);
        $client->shouldReceive('recordOpportunity')
            ->once()
            ->with($command['idempotency_key'], $command['payload'])
            ->andReturn($this->acknowledgement());

        app()->call([$legacyQueuedJob, 'handle'], ['client' => $client]);

        $export = $opportunity->engineOpportunityExport()->sole();
        $this->assertSame(TradingEngineOpportunityExport::STATUS_ACKNOWLEDGED, $export->status);
        $this->assertSame(1, $export->attempt_count);
        $this->assertSame($command['idempotency_key'], $export->idempotency_key);
    }

    public function test_disabled_export_is_durably_skipped_without_queue_dispatch(): void
    {
        config()->set('services.trading_engine.opportunity_export_enabled', false);
        $user = User::factory()->create();

        $result = app(TradeOpportunityService::class)->qualify($this->candidate(), $user);

        Queue::assertNothingPushed();
        $export = $result['opportunity']->engineOpportunityExport()->sole();
        $this->assertSame(TradingEngineOpportunityExport::STATUS_SKIPPED, $export->status);
        $this->assertSame('EXPORT_DISABLED', $export->last_error_code);
        $this->assertNull($export->queued_at);
        $this->assertNotNull($export->skipped_at);
    }

    public function test_exhausted_transport_timeout_is_durably_ambiguous(): void
    {
        [$opportunity, $command] = $this->queuedOpportunity();
        $job = new SubmitTradingEngineOpportunity($command['payload'], $command['idempotency_key']);
        $timeout = new TradingEngineException('TRANSPORT_TIMEOUT', 'The response may have been lost.', true);
        $client = Mockery::mock(TradingEngineClient::class);
        $client->shouldReceive('recordOpportunity')->once()->andThrow($timeout);

        try {
            $job->handle($client, app(TradingEngineOpportunityExportTracker::class));
            $this->fail('Expected the timeout to be retried.');
        } catch (TradingEngineException) {
        }

        $job->failed($timeout);
        $export = $opportunity->engineOpportunityExport()->sole();

        $this->assertSame(TradingEngineOpportunityExport::STATUS_ACCEPTANCE_UNKNOWN, $export->status);
        $this->assertSame('TRANSPORT_TIMEOUT', $export->last_error_code);
        $this->assertNull($export->permanently_failed_at);
    }

    public function test_attempt_fencing_blocks_duplicates_and_stale_callbacks_after_recovery(): void
    {
        [$opportunity] = $this->queuedOpportunity();
        $tracker = app(TradingEngineOpportunityExportTracker::class);
        $export = $opportunity->engineOpportunityExport()->sole();

        $firstAttempt = $tracker->beginAttempt($export);
        $this->assertSame(1, $firstAttempt);
        $this->assertNull($tracker->beginAttempt($export));

        $this->travel(121)->seconds();
        $secondAttempt = $tracker->beginAttempt($export);
        $this->assertSame(2, $secondAttempt);

        $tracker->acknowledged($export, $this->acknowledgement(), $secondAttempt);
        $tracker->retrying($export, 'TRANSPORT_TIMEOUT', $firstAttempt);
        $tracker->acceptanceUnknown($export, 'JOB_FAILED', $firstAttempt);

        $export->refresh();
        $this->assertSame(TradingEngineOpportunityExport::STATUS_ACKNOWLEDGED, $export->status);
        $this->assertSame(2, $export->attempt_count);
        $this->assertNull($export->last_error_code);
        $this->assertSame('IMMEDIATE', strtoupper((string) config('database.connections.sqlite.transaction_mode')));
        $this->assertGreaterThan(120, (int) config('queue.connections.database.retry_after'));
        $this->assertNull(config('queue.connections.database.connection'));
    }

    public function test_export_tracking_and_database_queue_persistence_roll_back_together(): void
    {
        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        [$opportunity, $command] = $this->queuedOpportunity();
        $opportunity->engineOpportunityExport()->delete();
        $this->assertDatabaseCount('jobs', 0);

        try {
            app(TradingEngineOpportunityExportTracker::class)->queueAndDispatch(
                $opportunity,
                $command['idempotency_key'],
                function () use ($command): void {
                    $this->realQueue->connection('database')->push(
                        new SubmitTradingEngineOpportunity($command['payload'], $command['idempotency_key']),
                    );

                    throw new RuntimeException('Simulated process interruption before transaction commit.');
                },
            );
            $this->fail('Expected the simulated interruption.');
        } catch (RuntimeException) {
        }

        $this->assertDatabaseCount('trading_engine_opportunity_exports', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_actual_pre_stage_b_serialized_job_payload_remains_executable(): void
    {
        $opportunity = TradeOpportunity::factory()->for(User::factory()->create())->create([
            'id' => 41,
            'chain' => 'solana',
            'scanner' => 'new-token',
            'discovery_key' => str_repeat('a', 64),
            'address' => 'So11111111111111111111111111111111111111112',
        ]);
        $serialized = base64_decode(
            'TzozOToiQXBwXEpvYnNcU3VibWl0VHJhZGluZ0VuZ2luZU9wcG9ydHVuaXR5IjoyOntzOjc6InBheWxvYWQiO2E6MTp7czo2OiJzb3VyY2UiO2E6MTp7czoxNDoib3Bwb3J0dW5pdHlfaWQiO3M6MjoiNDEiO319czoxNDoiaWRlbXBvdGVuY3lLZXkiO3M6MzI6Im9wcG9ydHVuaXR5OnJlY29yZDpsYXJhdmVsOjQxOnYxIjt9',
            true,
        );
        $this->assertIsString($serialized);
        $job = unserialize($serialized, ['allowed_classes' => [SubmitTradingEngineOpportunity::class]]);
        $this->assertInstanceOf(SubmitTradingEngineOpportunity::class, $job);
        $client = Mockery::mock(TradingEngineClient::class);
        $client->shouldReceive('recordOpportunity')
            ->once()
            ->with('opportunity:record:laravel:41:v1', ['source' => ['opportunity_id' => '41']])
            ->andReturn($this->acknowledgement());

        $job->handle($client, app(TradingEngineOpportunityExportTracker::class));

        $export = $opportunity->engineOpportunityExport()->sole();
        $this->assertSame(TradingEngineOpportunityExport::STATUS_ACKNOWLEDGED, $export->status);
        $this->assertSame(1, $export->attempt_count);
    }

    /** @return array{0: TradeOpportunity, 1: array{payload: array<string, mixed>, idempotency_key: string}} */
    private function queuedOpportunity(): array
    {
        $result = app(TradeOpportunityService::class)->qualify(
            $this->candidate(),
            User::factory()->create(),
        );
        $opportunity = $result['opportunity'];

        return [
            $opportunity,
            app(TradingEngineOpportunityCommandFactory::class)->make($opportunity),
        ];
    }

    /** @return array<string, mixed> */
    private function candidate(): array
    {
        return [
            'chain' => 'solana',
            'address' => 'So11111111111111111111111111111111111111112',
            'symbol' => 'STAGEB',
            'name' => 'Stage B',
            'discovery_key' => str_repeat('b', 64),
            'discovery_market_cap' => 10_000,
            'entry_market_cap' => 12_000,
            'entry_price' => '0.00000125',
            'entry_liquidity' => 3_000,
            'volume' => 800,
            'move_since_discovery_percent' => 20,
            'scanner' => 'new-token',
            'send_notification' => false,
            'meta' => ['entry_source' => 'birdeye_provisional'],
        ];
    }

    /** @return array{operationId: string, opportunityId: string, eventId: string, status: string, duplicate: bool} */
    private function acknowledgement(): array
    {
        return [
            'operationId' => '01K9ABCDEFGHJKMNPQRSTVWXYZ',
            'opportunityId' => '01K9ZYXWVTSRQPNMKJHGFEDCBA',
            'eventId' => '01K9ZYXWVTSRQPNMKJHGFEDCBB',
            'status' => 'accepted',
            'duplicate' => false,
        ];
    }
}
