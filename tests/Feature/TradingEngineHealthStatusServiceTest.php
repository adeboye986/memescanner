<?php

namespace Tests\Feature;

use App\Services\TradingEngine\TradingEngineClient;
use App\Services\TradingEngine\TradingEngineHealthStatusService;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class TradingEngineHealthStatusServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'services.operations.cache_store' => 'array',
            'services.trading_engine.enabled' => true,
        ]);
        Cache::store('array')->clear();
    }

    public function test_it_uses_authenticated_supported_health_methods_and_caches_the_snapshot(): void
    {
        $client = $this->mock(TradingEngineClient::class);
        $client->shouldReceive('liveness')->once()->andReturn([
            'status' => 'ok',
            'service' => 'trading-engine',
            'version' => '5c1aa7f',
            'timestamp' => '2026-10-23T10:00:00.000Z',
        ]);
        $client->shouldReceive('readiness')->once()->andReturn([
            'status' => 'ready',
            'service' => 'trading-engine',
            'version' => '5c1aa7f',
            'timestamp' => '2026-10-23T10:00:00.000Z',
            'dependencies' => ['postgres' => 'up', 'redis' => 'not_required'],
        ]);

        $service = app(TradingEngineHealthStatusService::class);
        $first = $service->status();
        $second = $service->status();

        $this->assertSame($first, $second);
        $this->assertSame('reachable', $first['connectivity']);
        $this->assertSame('ok', $first['liveness']);
        $this->assertSame('ready', $first['readiness']);
        $this->assertSame('up', $first['postgres']);
        $this->assertSame('not_available', $first['workflow_leadership']);
        $this->assertNotNull($first['last_successful_response_at']);
    }

    public function test_it_reports_partial_health_without_inventing_readiness_or_leadership(): void
    {
        $client = $this->mock(TradingEngineClient::class);
        $client->shouldReceive('liveness')->once()->andReturn([
            'status' => 'ok',
            'service' => 'trading-engine',
            'version' => '5c1aa7f',
            'timestamp' => '2026-10-23T10:00:00.000Z',
        ]);
        $client->shouldReceive('readiness')->once()->andThrow(new RuntimeException('secret database endpoint'));

        $status = app(TradingEngineHealthStatusService::class)->status();

        $this->assertSame('reachable', $status['connectivity']);
        $this->assertSame('ok', $status['liveness']);
        $this->assertSame('unavailable', $status['readiness']);
        $this->assertSame('unknown', $status['postgres']);
        $this->assertSame('not_available', $status['workflow_leadership']);
        $this->assertStringNotContainsString('secret', json_encode($status, JSON_THROW_ON_ERROR));
    }

    public function test_it_fails_closed_without_exposing_remote_errors(): void
    {
        $client = $this->mock(TradingEngineClient::class);
        $client->shouldReceive('liveness')->once()->andThrow(new RuntimeException('private-key-material'));
        $client->shouldReceive('readiness')->once()->andThrow(new RuntimeException('database-password'));

        $status = app(TradingEngineHealthStatusService::class)->status();

        $this->assertSame('unavailable', $status['connectivity']);
        $this->assertSame('unavailable', $status['liveness']);
        $this->assertSame('unavailable', $status['readiness']);
        $this->assertSame('unknown', $status['postgres']);
        $this->assertNull($status['last_successful_response_at']);
        $this->assertStringNotContainsString('password', json_encode($status, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('private-key', json_encode($status, JSON_THROW_ON_ERROR));
    }

    public function test_disabled_engine_never_calls_the_remote_client(): void
    {
        config()->set('services.trading_engine.enabled', false);
        $client = $this->mock(TradingEngineClient::class);
        $client->shouldNotReceive('liveness');
        $client->shouldNotReceive('readiness');

        $status = app(TradingEngineHealthStatusService::class)->status();

        $this->assertSame('disabled', $status['connectivity']);
        $this->assertSame('disabled', $status['liveness']);
        $this->assertSame('disabled', $status['readiness']);
        $this->assertSame('unknown', $status['postgres']);
        $this->assertSame('not_available', $status['workflow_leadership']);
    }
}
