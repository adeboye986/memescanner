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
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Concerns\RefreshesPaperTradingDatabase;
use Tests\TestCase;

class StageBOpportunityExportStateTest extends TestCase
{
    use RefreshesPaperTradingDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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
            ->andReturn($this->acknowledgement());

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
