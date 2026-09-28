<?php

namespace Tests\Feature;

use App\Models\TradingEngineEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TradingEngineOpportunityWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const PATH = '/internal/trading-engine/events';

    private const SECRET = 'test-only-webhook-secret-32-bytes-long';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-28T12:00:00Z');
        config()->set([
            'services.trading_engine.enabled' => true,
            'services.trading_engine.webhook_secret' => self::SECRET,
            'services.trading_engine.webhook_timestamp_tolerance_seconds' => 60,
            'services.trading_engine.webhook_body_max_bytes' => 262144,
            'services.trading_engine.webhook_rate_limit_per_minute' => 600,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_known_opportunity_event_is_stored_and_duplicate_is_a_noop_without_trading_side_effects(): void
    {
        $event = $this->event();
        $rawBody = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->sendSigned($rawBody)->assertAccepted()->assertJsonPath('handling_status', TradingEngineEvent::STATUS_STORED);
        $this->sendSigned($rawBody)->assertOk()->assertJsonPath('duplicate', true);

        $stored = TradingEngineEvent::query()->sole();
        $this->assertSame('opportunity.recorded.v1', $stored->event_type);
        $this->assertSame($event['payload'], $stored->payload);
        $this->assertNull($stored->handled_at);
        $this->assertDatabaseCount('trading_engine_event_inbox', 1);
        $this->assertDatabaseCount('paper_wallets', 0);
        $this->assertDatabaseCount('paper_positions', 0);
        $this->assertDatabaseCount('trade_opportunities', 0);
        $this->assertDatabaseCount('solana_swap_attempts', 0);
        $this->assertDatabaseCount('ethereum_swap_attempts', 0);
        $this->assertDatabaseCount('live_positions', 0);
    }

    public function test_malformed_opportunity_payload_is_rejected_without_inbox_or_trading_writes(): void
    {
        $event = $this->event();
        $event['payload']['market_snapshot']['market_cap_usd']['value'] = 12000;
        $rawBody = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->sendSigned($rawBody)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'EVENT_ENVELOPE_INVALID');

        $this->assertDatabaseCount('trading_engine_event_inbox', 0);
        $this->assertDatabaseCount('trade_opportunities', 0);
        $this->assertDatabaseCount('paper_positions', 0);
    }

    public function test_base58_looking_solana_address_with_wrong_byte_length_is_rejected(): void
    {
        $event = $this->event();
        $event['payload']['asset']['address'] = str_repeat('2', 32);
        $rawBody = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->sendSigned($rawBody)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'EVENT_ENVELOPE_INVALID');

        $this->assertDatabaseCount('trading_engine_event_inbox', 0);
    }

    /** @return array<string, mixed> */
    private function event(): array
    {
        $payload = [
            'operation_id' => '01K9ABCDEFGHJKMNPQRSTVWXYZ',
            'opportunity_id' => '01K9ZYXWVTSRQPNMKJHGFEDCBA',
            'schema_version' => 1,
            'source' => [
                'system' => 'meme-scanner-laravel',
                'opportunity_id' => '42',
                'discovery_key' => str_repeat('a', 64),
                'scanner' => 'new-token',
            ],
            'subject' => ['control_plane_user_id' => '7'],
            'network' => ['id' => 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp'],
            'asset' => [
                'address' => 'So11111111111111111111111111111111111111112',
                'symbol' => 'MEME',
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

        return [
            'event_id' => '01K9ZYXWVTSRQPNMKJHGFEDCBB',
            'event_type' => 'opportunity.recorded.v1',
            'schema_version' => 1,
            'occurred_at' => '2026-09-28T12:00:00.000Z',
            'producer' => 'trading-engine',
            'aggregate_type' => 'opportunity',
            'aggregate_id' => $payload['opportunity_id'],
            'aggregate_version' => 1,
            'correlation_id' => 'opportunity-correlation',
            'causation_id' => $payload['operation_id'],
            'idempotency_key' => 'opportunity:record:laravel:42:v1',
            'traceparent' => '00-0123456789abcdef0123456789abcdef-0123456789abcdef-01',
            'payload' => $payload,
            'payload_sha256' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        ];
    }

    private function sendSigned(string $rawBody): TestResponse
    {
        $timestamp = (string) now()->timestamp;
        $event = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);
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
}
