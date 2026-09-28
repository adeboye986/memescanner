<?php

namespace Tests\Feature;

use App\Models\TradingEngineEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TradingEngineOpportunityEvaluationWebhookTest extends TestCase
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

    public function test_evaluated_event_is_independently_stored_and_duplicate_is_a_noop_without_trading_side_effects(): void
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
        $this->sendSigned($rawBody)
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        $stored = TradingEngineEvent::query()->sole();
        $this->assertSame('opportunity.evaluated.v1', $stored->event_type);
        $this->assertSame($event['payload'], $stored->payload);
        $this->assertNull($stored->handled_at);
        $this->assertDatabaseCount('trading_engine_event_inbox', 1);
        $this->assertDatabaseCount('trade_opportunities', 0);
        $this->assertDatabaseCount('paper_wallets', 0);
        $this->assertDatabaseCount('paper_positions', 0);
        $this->assertDatabaseCount('solana_swap_attempts', 0);
        $this->assertDatabaseCount('ethereum_swap_attempts', 0);
        $this->assertDatabaseCount('live_positions', 0);
    }

    public function test_strict_contract_rejects_malformed_evaluation_events_without_inbox_or_trading_writes(): void
    {
        $extraPayload = $this->event();
        $extraPayload['payload']['execution_mode'] = 'PAPER';
        $wrongPolicy = $this->event();
        $wrongPolicy['payload']['policy']['version'] = 2;
        $unorderedReasons = $this->event();
        $unorderedReasons['payload']['reason_codes'] = [
            'SECURITY_EVIDENCE_CONTRADICTORY',
            'MARKET_CAP_MISSING',
        ];
        $paddedDecimal = $this->event();
        $paddedDecimal['payload']['evidence']['facts']['market_cap_usd'] = '10000.000';
        $wrongChecks = $this->event();
        array_pop($wrongChecks['payload']['evidence']['checks']);
        $wrongAggregate = $this->event();
        $wrongAggregate['aggregate_id'] = '01K9ZYXWVTSRQPNMKJHGFEDCBC';

        foreach ([
            $extraPayload,
            $wrongPolicy,
            $unorderedReasons,
            $paddedDecimal,
            $wrongChecks,
            $wrongAggregate,
        ] as $event) {
            $this->sendSigned($this->encode($event))
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'EVENT_ENVELOPE_INVALID');
        }

        $this->assertDatabaseCount('trading_engine_event_inbox', 0);
        $this->assertDatabaseCount('trade_opportunities', 0);
        $this->assertDatabaseCount('paper_positions', 0);
        $this->assertDatabaseCount('live_positions', 0);
    }

    /** @return array<string, mixed> */
    private function event(): array
    {
        $payload = [
            'evaluation_id' => '01K9ZYXWVTSRQPNMKJHGFEDCBA',
            'opportunity_id' => '01K9ABCDEFGHJKMNPQRSTVWXYZ',
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
                'profile' => 'ethereum:momentum',
                'facts' => [
                    'market_cap_usd' => '10000',
                    'liquidity_usd' => '2000',
                    'volume_5m_usd' => '500',
                    'move_since_discovery_percent' => '35',
                    'classification' => null,
                    'pair_address' => '0x2222222222222222222222222222222222222222',
                    'pair_available' => true,
                    'requested_token_is_base' => true,
                    'security_status' => 'unavailable',
                    'security_provider' => null,
                    'security_passed' => null,
                ],
                'checks' => [
                    ['check' => 'market_cap', 'status' => 'passed'],
                    ['check' => 'liquidity', 'status' => 'passed'],
                    ['check' => 'volume_5m', 'status' => 'passed'],
                    ['check' => 'movement', 'status' => 'passed'],
                    ['check' => 'pair_validation', 'status' => 'passed'],
                    ['check' => 'security', 'status' => 'passed'],
                ],
            ],
            'result_sha256' => str_repeat('d', 64),
        ];

        return [
            'event_id' => '01K9ZYXWVTSRQPNMKJHGFEDCBB',
            'event_type' => 'opportunity.evaluated.v1',
            'schema_version' => 1,
            'occurred_at' => '2026-09-28T12:00:00.000Z',
            'producer' => 'trading-engine',
            'aggregate_type' => 'opportunity_evaluation',
            'aggregate_id' => $payload['evaluation_id'],
            'aggregate_version' => 1,
            'correlation_id' => 'opportunity-correlation',
            'causation_id' => '01K9ZYXWVTSRQPNMKJHGFEDCBC',
            'idempotency_key' => 'evaluation:01K9ABCDEFGHJKMNPQRSTVWXYZ:migration-opportunity-snapshot:1',
            'traceparent' => '00-0123456789abcdef0123456789abcdef-0123456789abcdef-01',
            'payload' => $payload,
            'payload_sha256' => str_repeat('e', 64),
        ];
    }

    /** @param  array<string, mixed>  $value */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
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
