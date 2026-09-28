<?php

namespace Tests\Feature;

use App\Models\TradingEngineEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TradingEngineWebhookTest extends TestCase
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

    public function test_valid_noop_event_is_authenticated_and_stored_without_side_effects(): void
    {
        $event = $this->event();
        $rawBody = $this->encode($event);

        $this->sendSigned($rawBody)
            ->assertAccepted()
            ->assertExactJson([
                'accepted' => true,
                'duplicate' => false,
                'event_id' => $event['event_id'],
                'handling_status' => TradingEngineEvent::STATUS_STORED,
            ]);

        $stored = TradingEngineEvent::query()->sole();

        $this->assertSame($event['event_id'], $stored->event_id);
        $this->assertSame($event, $stored->event_envelope);
        $this->assertSame($event['payload'], $stored->payload);
        $this->assertSame(hash('sha256', $rawBody), $stored->raw_body_sha256);
        $this->assertSame(TradingEngineEvent::STATUS_STORED, $stored->handling_status);
        $this->assertNull($stored->handled_at);
        $this->assertDatabaseCount('paper_wallets', 0);
        $this->assertDatabaseCount('paper_positions', 0);
        $this->assertDatabaseCount('trade_opportunities', 0);
        $this->assertDatabaseCount('solana_swap_attempts', 0);
        $this->assertDatabaseCount('ethereum_swap_attempts', 0);
        $this->assertDatabaseCount('live_positions', 0);
    }

    public function test_authenticated_unknown_event_is_acknowledged_and_stored_unhandled(): void
    {
        $event = $this->event([
            'event_type' => 'foundation.future_event.v1',
            'aggregate_type' => 'future_foundation',
            'payload' => ['future_value' => true],
        ]);

        $this->sendSigned($this->encode($event))
            ->assertAccepted()
            ->assertJsonPath('handling_status', TradingEngineEvent::STATUS_UNHANDLED);

        $this->assertDatabaseHas('trading_engine_event_inbox', [
            'event_id' => $event['event_id'],
            'handling_status' => TradingEngineEvent::STATUS_UNHANDLED,
        ]);
    }

    public function test_empty_object_payload_remains_an_object_in_the_json_inbox(): void
    {
        $event = $this->event([
            'event_type' => 'foundation.future_event.v1',
            'aggregate_type' => 'future_foundation',
            'payload' => ['temporary' => true],
        ]);
        $rawBody = str_replace('"payload":{"temporary":true}', '"payload":{}', $this->encode($event));

        $this->sendSigned($rawBody)->assertAccepted();

        $storedJson = DB::table('trading_engine_event_inbox')->value('event_envelope');
        $storedPayload = DB::table('trading_engine_event_inbox')->value('payload');
        $this->assertStringContainsString('"payload":{}', $storedJson);
        $this->assertSame('{}', $storedPayload);
    }

    public function test_missing_or_short_webhook_secret_fails_closed(): void
    {
        foreach ([null, 'too-short'] as $secret) {
            config()->set('services.trading_engine.webhook_secret', $secret);

            $this->sendSigned($this->encode($this->event()))
                ->assertServiceUnavailable()
                ->assertJsonPath('error.code', 'WEBHOOK_CONFIGURATION_INVALID');
        }

        $this->assertDatabaseCount('trading_engine_event_inbox', 0);
    }

    public function test_disabled_integration_fails_closed(): void
    {
        config()->set('services.trading_engine.enabled', false);

        $this->sendSigned($this->encode($this->event()))
            ->assertServiceUnavailable()
            ->assertJsonPath('error.code', 'WEBHOOK_CONFIGURATION_INVALID');

        $this->assertDatabaseCount('trading_engine_event_inbox', 0);
    }

    public function test_missing_required_headers_are_rejected(): void
    {
        $rawBody = $this->encode($this->event());
        $cases = [
            'HTTP_X_ENGINE_TIMESTAMP' => 401,
            'HTTP_X_ENGINE_SIGNATURE' => 401,
            'HTTP_X_ENGINE_EVENT_ID' => 422,
            'HTTP_X_CORRELATION_ID' => 422,
            'HTTP_TRACEPARENT' => 422,
        ];

        foreach ($cases as $header => $status) {
            $this->sendSigned($rawBody, serverOverrides: [$header => null])->assertStatus($status);
        }

        $this->assertDatabaseCount('trading_engine_event_inbox', 0);
    }

    public function test_malformed_uppercase_and_wrong_signatures_are_rejected(): void
    {
        $rawBody = $this->encode($this->event());

        foreach (['not-a-signature', 'v1='.str_repeat('A', 64), 'v1='.str_repeat('0', 64)] as $signature) {
            $this->sendSigned($rawBody, serverOverrides: [
                'HTTP_X_ENGINE_SIGNATURE' => $signature,
            ])->assertUnauthorized()->assertJsonPath('error.code', 'WEBHOOK_AUTHENTICATION_FAILED');
        }

        $this->assertDatabaseCount('trading_engine_event_inbox', 0);
    }

    public function test_body_tampering_after_signing_is_rejected(): void
    {
        $originalBody = $this->encode($this->event());
        $tamperedBody = str_replace('test message', 'tampered message', $originalBody);

        $this->sendSigned($tamperedBody, signedBody: $originalBody)
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'WEBHOOK_AUTHENTICATION_FAILED');

        $this->assertDatabaseCount('trading_engine_event_inbox', 0);
    }

    public function test_signature_binds_the_exact_path_and_query(): void
    {
        $rawBody = $this->encode($this->event());
        $pathWithQuery = self::PATH.'?delivery=retry%2Fone';

        $this->sendSigned($rawBody, path: $pathWithQuery, signedPath: self::PATH)->assertUnauthorized();
        $this->sendSigned($rawBody, path: $pathWithQuery)->assertAccepted();

        $this->assertDatabaseCount('trading_engine_event_inbox', 1);
    }

    public function test_stale_future_and_non_integer_timestamps_are_rejected(): void
    {
        $rawBody = $this->encode($this->event());

        foreach ([(string) (now()->timestamp - 61), (string) (now()->timestamp + 61), '1720000000.5', 'not-an-integer'] as $timestamp) {
            $this->sendSigned($rawBody, timestamp: $timestamp)->assertUnauthorized();
        }

        $this->assertDatabaseCount('trading_engine_event_inbox', 0);
    }

    public function test_malformed_json_is_rejected_only_after_valid_authentication(): void
    {
        $this->sendSigned('{"event_id":')
            ->assertBadRequest()
            ->assertJsonPath('error.code', 'MALFORMED_JSON');

        $this->assertDatabaseCount('trading_engine_event_inbox', 0);
    }

    public function test_invalid_producer_schema_and_unexpected_fields_are_rejected(): void
    {
        $missingField = $this->event();
        unset($missingField['aggregate_id']);

        $invalidEvents = [
            $this->event(['producer' => 'not-the-engine']),
            $this->event(['schema_version' => 0]),
            $this->event(['occurred_at' => '2026-02-30T12:00:00.000Z']),
            $missingField,
            [...$this->event(), 'unexpected' => true],
            $this->event(['payload' => []]),
            $this->event(['payload' => [...$this->event()['payload'], 'unexpected' => true]]),
        ];

        foreach ($invalidEvents as $event) {
            $this->sendSigned($this->encode($event))
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'EVENT_ENVELOPE_INVALID');
        }

        $this->assertDatabaseCount('trading_engine_event_inbox', 0);
    }

    public function test_header_values_must_match_the_authenticated_envelope(): void
    {
        $rawBody = $this->encode($this->event());

        foreach ([
            ['HTTP_X_ENGINE_EVENT_ID' => '01K9ZYXWVTSRQPNMKJHGFEDCBB'],
            ['HTTP_X_CORRELATION_ID' => 'different-correlation'],
            ['HTTP_TRACEPARENT' => '00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01'],
        ] as $override) {
            $this->sendSigned($rawBody, serverOverrides: $override)
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'EVENT_HEADER_MISMATCH');
        }

        $this->assertDatabaseCount('trading_engine_event_inbox', 0);
    }

    public function test_oversized_body_is_rejected_before_authentication_or_persistence(): void
    {
        config()->set('services.trading_engine.webhook_body_max_bytes', 1024);

        $this->call('POST', self::PATH, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], str_repeat('x', 1025))
            ->assertStatus(413)
            ->assertJsonPath('error.code', 'WEBHOOK_BODY_TOO_LARGE');

        $this->assertDatabaseCount('trading_engine_event_inbox', 0);
    }

    public function test_identical_delivery_is_acknowledged_once_through_the_unique_constraint_path(): void
    {
        $rawBody = $this->encode($this->event());

        $this->sendSigned($rawBody)->assertAccepted()->assertJsonPath('duplicate', false);
        $this->sendSigned($rawBody)->assertOk()->assertJsonPath('duplicate', true);

        $this->assertDatabaseCount('trading_engine_event_inbox', 1);
    }

    public function test_reused_event_id_with_different_raw_content_is_a_conflict_and_never_overwrites(): void
    {
        $original = $this->event();
        $changed = $this->event(['occurred_at' => '2026-09-28T12:00:01.000Z']);

        $this->sendSigned($this->encode($original))->assertAccepted();
        $this->sendSigned($this->encode($changed))
            ->assertConflict()
            ->assertJsonPath('error.code', 'EVENT_ID_CONFLICT');

        $stored = TradingEngineEvent::query()->sole();
        $this->assertSame($original['occurred_at'], $stored->event_envelope['occurred_at']);
    }

    public function test_payload_hash_is_stored_as_evidence_without_php_json_recomputation(): void
    {
        $event = $this->event(['payload_sha256' => str_repeat('0', 64)]);

        $this->sendSigned($this->encode($event))->assertAccepted();

        $this->assertDatabaseHas('trading_engine_event_inbox', [
            'event_id' => $event['event_id'],
            'payload_sha256' => str_repeat('0', 64),
        ]);
    }

    public function test_database_failure_returns_a_retryable_non_success_response(): void
    {
        Schema::drop('trading_engine_event_inbox');

        $this->sendSigned($this->encode($this->event()))
            ->assertServiceUnavailable()
            ->assertExactJson([
                'error' => [
                    'code' => 'EVENT_INBOX_UNAVAILABLE',
                    'message' => 'The trading engine event inbox is temporarily unavailable.',
                ],
            ]);
    }

    public function test_secrets_signatures_and_raw_body_are_absent_from_error_responses(): void
    {
        Log::spy();
        $rawBody = $this->encode($this->event(['payload' => [
            'operation_id' => '01K9ABCDEFGHJKMNPQRSTVWXYZ',
            'accepted_by' => 'meme-scanner-laravel',
            'message' => 'raw-body-canary-value',
        ]]));
        $signature = 'v1='.str_repeat('0', 64);

        $response = $this->sendSigned($rawBody, serverOverrides: [
            'HTTP_X_ENGINE_SIGNATURE' => $signature,
        ])->assertUnauthorized();

        $response->assertDontSee(self::SECRET, false);
        $response->assertDontSee($signature, false);
        $response->assertDontSee('raw-body-canary-value', false);
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');
    }

    public function test_endpoint_is_post_only_and_has_no_web_session_or_csrf_middleware(): void
    {
        $route = Route::getRoutes()->getByName('internal.trading-engine.events');

        $this->assertNotNull($route);
        $this->assertSame(['POST'], $route->methods());
        $this->assertNotContains('web', $route->gatherMiddleware());
        $this->assertNotContains('auth', $route->gatherMiddleware());
        $this->getJson(self::PATH)->assertMethodNotAllowed();
        $this->sendSigned($this->encode($this->event()), serverOverrides: [
            'HTTP_X_ENGINE_SIGNATURE' => 'v1='.str_repeat('0', 64),
        ])->assertUnauthorized()->assertHeaderMissing('Location');
    }

    public function test_dedicated_rate_limit_is_enforced_without_affecting_duplicate_semantics(): void
    {
        config()->set('services.trading_engine.webhook_rate_limit_per_minute', 2);

        foreach (['A', 'B'] as $suffix) {
            $event = $this->event(['event_id' => '01K9ZYXWVTSRQPNMKJHGFEDCB'.$suffix]);
            $this->sendSigned($this->encode($event))->assertAccepted();
        }

        $third = $this->event(['event_id' => '01K9ZYXWVTSRQPNMKJHGFEDCBC']);
        $this->sendSigned($this->encode($third))->assertTooManyRequests();
        $this->assertDatabaseCount('trading_engine_event_inbox', 2);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function event(array $overrides = []): array
    {
        $payload = $overrides['payload'] ?? [
            'operation_id' => '01K9ABCDEFGHJKMNPQRSTVWXYZ',
            'accepted_by' => 'meme-scanner-laravel',
            'message' => 'test message',
        ];

        return [
            'event_id' => '01K9ZYXWVTSRQPNMKJHGFEDCBA',
            'event_type' => 'foundation.noop_accepted.v1',
            'schema_version' => 1,
            'occurred_at' => '2026-09-28T12:00:00.000Z',
            'producer' => 'trading-engine',
            'aggregate_type' => 'foundation_command',
            'aggregate_id' => '01K9ABCDEFGHJKMNPQRSTVWXYZ',
            'aggregate_version' => 1,
            'correlation_id' => 'correlation-batch-2',
            'causation_id' => '01K9ABCDEFGHJKMNPQRSTVWXYZ',
            'idempotency_key' => 'phase2:batch2:test',
            'traceparent' => '00-0123456789abcdef0123456789abcdef-0123456789abcdef-01',
            'payload' => $payload,
            'payload_sha256' => hash('sha256', $this->encode($payload)),
            ...$overrides,
        ];
    }

    /** @param array<string, mixed> $value */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, string|null> $serverOverrides */
    private function sendSigned(
        string $rawBody,
        string $path = self::PATH,
        ?string $timestamp = null,
        ?string $signedBody = null,
        ?string $signedPath = null,
        array $serverOverrides = [],
    ): TestResponse {
        $timestamp ??= (string) now()->timestamp;
        $signedBody ??= $rawBody;
        $signedPath ??= $path;
        $event = json_decode($rawBody, true);
        $signature = hash_hmac('sha256', $timestamp.'.POST.'.$signedPath.'.'.$signedBody, self::SECRET);
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ENGINE_TIMESTAMP' => $timestamp,
            'HTTP_X_ENGINE_SIGNATURE' => 'v1='.$signature,
            'HTTP_X_ENGINE_EVENT_ID' => is_array($event) ? (string) ($event['event_id'] ?? '') : '',
            'HTTP_X_CORRELATION_ID' => is_array($event) ? (string) ($event['correlation_id'] ?? '') : '',
            'HTTP_TRACEPARENT' => is_array($event) ? (string) ($event['traceparent'] ?? '') : '',
        ];

        foreach ($serverOverrides as $key => $value) {
            if ($value === null) {
                unset($server[$key]);
            } else {
                $server[$key] = $value;
            }
        }

        return $this->call('POST', $path, [], [], [], $server, $rawBody);
    }
}
