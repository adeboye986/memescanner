<?php

namespace Tests\Unit\Services\TradingEngine;

use App\Exceptions\TradingEngineException;
use App\Services\TradingEngine\TradingEngineClient;
use App\Services\TradingEngine\TradingEngineServiceAssertionFactory;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TradingEngineClientTest extends TestCase
{
    private const CORRELATION_ID = 'laravel-correlation-1';

    private const TRACEPARENT = '00-11111111111111111111111111111111-2222222222222222-01';

    protected function setUp(): void
    {
        parent::setUp();

        $fixture = $this->keyFixture();
        config()->set('services.trading_engine.enabled', true);
        config()->set('services.trading_engine.base_url', 'https://engine.test');
        config()->set('services.trading_engine.service_issuer', 'laravel-test');
        config()->set('services.trading_engine.service_audience', 'engine-test');
        config()->set('services.trading_engine.service_subject', 'laravel-service');
        config()->set('services.trading_engine.private_key_base64', $fixture['private_key_base64']);
        config()->set('services.trading_engine.assertion_lifetime_seconds', 30);
        config()->set('services.trading_engine.connect_timeout_seconds', 3);
        config()->set('services.trading_engine.timeout_seconds', 8);
    }

    public function test_integration_is_disabled_by_default_and_sends_no_request(): void
    {
        config()->set('services.trading_engine.enabled', false);
        Http::preventStrayRequests();

        try {
            app(TradingEngineClient::class)->liveness();
            $this->fail('A disabled trading engine integration made a request.');
        } catch (TradingEngineException $exception) {
            $this->assertSame('INTEGRATION_DISABLED', $exception->errorCode);
        }

        Http::assertNothingSent();
    }

    public function test_rejects_http_outside_local_and_testing_environments(): void
    {
        $this->app['env'] = 'production';
        config()->set('services.trading_engine.base_url', 'http://engine.test');
        Http::preventStrayRequests();

        try {
            app(TradingEngineClient::class)->liveness();
            $this->fail('An insecure production engine URL was accepted.');
        } catch (TradingEngineException $exception) {
            $this->assertSame('CONFIGURATION_INVALID', $exception->errorCode);
            $this->assertStringContainsString('requires HTTPS', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_liveness_uses_authenticated_scope_and_preserves_safe_trace_context(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://engine.test/v1/health/live' => fn (Request $request): PromiseInterface => $this->responseFor(
                $request,
                $this->liveHealth(),
            ),
        ]);

        $result = app(TradingEngineClient::class)->liveness(self::CORRELATION_ID, self::TRACEPARENT);

        $this->assertSame('ok', $result['status']);
        Http::assertSent(function (Request $request): bool {
            $authorization = $request->header('Authorization')[0] ?? '';
            $claims = $this->payload(str_replace('Bearer ', '', $authorization));

            return $request->method() === 'GET'
                && $request->url() === 'https://engine.test/v1/health/live'
                && $request->hasHeader('Accept', 'application/json')
                && $request->hasHeader('X-Correlation-Id', self::CORRELATION_ID)
                && $request->hasHeader('traceparent', self::TRACEPARENT)
                && $claims['scope'] === 'health:read';
        });
    }

    public function test_readiness_accepts_healthy_postgresql_with_redis_not_required(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://engine.test/v1/health/ready' => fn (Request $request): PromiseInterface => $this->responseFor(
                $request,
                $this->readiness(),
            ),
        ]);

        $result = app(TradingEngineClient::class)->readiness(self::CORRELATION_ID, self::TRACEPARENT);

        $this->assertSame('ok', $result['status']);
        $this->assertSame('up', $result['dependencies']['postgres']);
        $this->assertSame('not_required', $result['dependencies']['redis']);
    }

    public function test_readiness_accepts_the_typed_dependency_unavailable_contract(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://engine.test/v1/health/ready' => fn (Request $request): PromiseInterface => $this->responseFor(
                $request,
                $this->readiness('unavailable', 'down', 'not_required'),
                503,
            ),
        ]);

        $result = app(TradingEngineClient::class)->readiness(self::CORRELATION_ID, self::TRACEPARENT);

        $this->assertSame('unavailable', $result['status']);
        $this->assertSame('down', $result['dependencies']['postgres']);
        $this->assertSame('not_required', $result['dependencies']['redis']);
    }

    public function test_version_validates_and_returns_the_exact_contract(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://engine.test/v1/version' => fn (Request $request): PromiseInterface => $this->responseFor($request, [
                'service' => 'meme-scanner-trading-engine',
                'version' => '0.1.0',
                'node' => 'v24.21.0',
            ]),
        ]);

        $result = app(TradingEngineClient::class)->version(self::CORRELATION_ID, self::TRACEPARENT);

        $this->assertSame([
            'service' => 'meme-scanner-trading-engine',
            'version' => '0.1.0',
            'node' => 'v24.21.0',
        ], $result);
    }

    #[DataProvider('malformedResponseProvider')]
    public function test_rejects_malformed_success_contracts(string $method, string $path, array $body): void
    {
        Http::preventStrayRequests();
        Http::fake([
            "https://engine.test{$path}" => fn (Request $request): PromiseInterface => $this->responseFor($request, $body),
        ]);

        try {
            app(TradingEngineClient::class)->{$method}(self::CORRELATION_ID, self::TRACEPARENT);
            $this->fail('A malformed engine success response was accepted.');
        } catch (TradingEngineException $exception) {
            $this->assertSame('INVALID_RESPONSE', $exception->errorCode);
        }
    }

    public static function malformedResponseProvider(): array
    {
        return [
            'liveness with an extra field' => ['liveness', '/v1/health/live', [
                'status' => 'ok', 'service' => 'engine', 'version' => '1',
                'timestamp' => '2026-09-27T12:00:00.000Z', 'secret' => 'unexpected',
            ]],
            'readiness without dependencies' => ['readiness', '/v1/health/ready', [
                'status' => 'ok', 'service' => 'engine', 'version' => '1',
                'timestamp' => '2026-09-27T12:00:00.000Z',
            ]],
            'version with a non-string runtime' => ['version', '/v1/version', [
                'service' => 'engine', 'version' => '1', 'node' => 24,
            ]],
        ];
    }

    public function test_redirect_response_is_rejected_without_following_location(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://engine.test/v1/health/live' => Http::response('', 302, [
                'Location' => 'https://unexpected.test/private',
            ]),
        ]);

        try {
            app(TradingEngineClient::class)->liveness();
            $this->fail('A redirect was accepted as an engine response.');
        } catch (TradingEngineException $exception) {
            $this->assertSame('UNEXPECTED_RESPONSE', $exception->errorCode);
            $this->assertSame(302, $exception->responseStatus);
        }

        Http::assertSentCount(1);
    }

    public function test_noop_sends_only_the_exact_synthetic_contract(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://engine.test/v1/commands/noop' => fn (Request $request): PromiseInterface => $this->responseFor(
                $request,
                $this->noopResponse(),
                202,
            ),
        ]);

        $result = app(TradingEngineClient::class)->noop(
            'verify:stable-1',
            'synthetic only',
            self::CORRELATION_ID,
            self::TRACEPARENT,
        );

        $this->assertSame('accepted', $result['status']);
        $this->assertFalse($result['duplicate']);
        Http::assertSent(function (Request $request): bool {
            $authorization = $request->header('Authorization')[0] ?? '';
            $claims = $this->payload(str_replace('Bearer ', '', $authorization));

            return $request->method() === 'POST'
                && $request->url() === 'https://engine.test/v1/commands/noop'
                && $request->hasHeader('Idempotency-Key', 'verify:stable-1')
                && $request->data() === ['message' => 'synthetic only']
                && $claims['scope'] === 'commands:noop';
        });
    }

    public function test_explicit_noop_retry_keeps_request_identity_and_uses_a_fresh_assertion(): void
    {
        $requests = [];
        Http::preventStrayRequests();
        Http::fake([
            'https://engine.test/v1/commands/noop' => function (Request $request) use (&$requests): PromiseInterface {
                $requests[] = $request;

                return $this->responseFor(
                    $request,
                    $this->noopResponse(duplicate: count($requests) > 1),
                    202,
                );
            },
        ]);
        $client = app(TradingEngineClient::class);

        $first = $client->noop('verify:retry-1', 'same request', self::CORRELATION_ID, self::TRACEPARENT);
        $second = $client->noop('verify:retry-1', 'same request', self::CORRELATION_ID, self::TRACEPARENT);

        $this->assertFalse($first['duplicate']);
        $this->assertTrue($second['duplicate']);
        $this->assertCount(2, $requests);
        $this->assertSame($requests[0]->body(), $requests[1]->body());
        $this->assertSame($requests[0]->header('Idempotency-Key'), $requests[1]->header('Idempotency-Key'));
        $this->assertNotSame($requests[0]->header('Authorization'), $requests[1]->header('Authorization'));
    }

    #[DataProvider('invalidNoopProvider')]
    public function test_invalid_noop_input_is_rejected_before_network_access(string $key, ?string $message): void
    {
        Http::preventStrayRequests();

        try {
            app(TradingEngineClient::class)->noop($key, $message);
            $this->fail('Invalid no-op input was accepted.');
        } catch (TradingEngineException $exception) {
            $this->assertSame('VALIDATION_FAILED', $exception->errorCode);
        }

        Http::assertNothingSent();
    }

    public static function invalidNoopProvider(): array
    {
        return [
            'invalid key characters' => ['unsafe key', null],
            'oversized message' => ['valid-key', str_repeat('x', 257)],
        ];
    }

    #[DataProvider('transportFailureProvider')]
    public function test_transport_failures_are_normalized_without_leaking_details(string $message, string $expectedCode): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://engine.test/v1/health/live' => Http::failedConnection($message),
        ]);

        try {
            app(TradingEngineClient::class)->liveness();
            $this->fail('A transport failure was not surfaced.');
        } catch (TradingEngineException $exception) {
            $this->assertSame($expectedCode, $exception->errorCode);
            $this->assertTrue($exception->retryable);
            $this->assertStringNotContainsString('private-key-or-token', $exception->getMessage());
        }
    }

    public static function transportFailureProvider(): array
    {
        return [
            'timeout' => ['Operation timed out with private-key-or-token', 'TRANSPORT_TIMEOUT'],
            'connection failure' => ['DNS failed with private-key-or-token', 'TRANSPORT_FAILURE'],
        ];
    }

    public function test_authentication_failure_is_normalized_without_exposing_engine_details(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://engine.test/v1/health/live' => Http::response([
                'error' => [
                    'code' => 'AUTH_INVALID',
                    'message' => 'private-key-or-token',
                    'retryable' => false,
                ],
                'correlationId' => 'engine-correlation',
            ], 401),
        ]);

        try {
            app(TradingEngineClient::class)->liveness();
            $this->fail('An authentication failure was not surfaced.');
        } catch (TradingEngineException $exception) {
            $this->assertSame('AUTHENTICATION_FAILED', $exception->errorCode);
            $this->assertSame(401, $exception->responseStatus);
            $this->assertStringNotContainsString('private-key-or-token', $exception->getMessage());
            $this->assertStringNotContainsString($this->keyFixture()['private_key_base64'], $exception->getMessage());
        }
    }

    public function test_paper_entry_uses_strict_authenticated_command_contract(): void
    {
        $client = $this->clientWithoutSodium();
        config()->set('services.trading_engine.paper_entry_integration_enabled', true);
        Http::preventStrayRequests();
        Http::fake([
            'https://engine.test/v1/commands/paper-entries' => fn (Request $request): PromiseInterface => $this->responseFor($request, [
                'operationId' => '01K9ABCDEFGHJKMNPQRSTVWXYZ',
                'walletId' => '01K9ABCDEFGHJKMNPQRSTVWXY1',
                'intentId' => '01K9ABCDEFGHJKMNPQRSTVWXY2',
                'orderId' => '01K9ABCDEFGHJKMNPQRSTVWXY3',
                'fillId' => '01K9ABCDEFGHJKMNPQRSTVWXY4',
                'positionId' => '01K9ABCDEFGHJKMNPQRSTVWXY5',
                'eventId' => '01K9ABCDEFGHJKMNPQRSTVWXY6',
                'status' => 'accepted',
                'duplicate' => false,
            ], 202),
        ]);

        $result = $client->executePaperEntry('paper:entry:laravel:101:v1', ['schema_version' => 1]);

        $this->assertSame('01K9ABCDEFGHJKMNPQRSTVWXY5', $result['positionId']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://engine.test/v1/commands/paper-entries'
            && $request->hasHeader('Idempotency-Key', 'paper:entry:laravel:101:v1')
            && $request->hasHeader('Authorization', 'Bearer test-service-assertion'));
    }

    public function test_paper_financial_observation_uses_dedicated_authenticated_command_contract(): void
    {
        $client = $this->clientWithoutSodium();
        config()->set([
            'services.trading_engine.paper_financial_lifecycle_enabled' => true,
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://engine.test/v1/commands/paper-financial-positions/observations' => fn (Request $request): PromiseInterface => $this->responseFor($request, [
                'operationId' => '01K9ABCDEFGHJKMNPQRSTVWXYZ',
                'positionId' => '01K9ABCDEFGHJKMNPQRSTVWXY1',
                'decisionId' => '01K9ABCDEFGHJKMNPQRSTVWXY2',
                'eventId' => '01K9ABCDEFGHJKMNPQRSTVWXY3',
                'settlementId' => null,
                'decision' => 'HOLD',
                'duplicate' => false,
            ], 202),
        ]);

        $result = $client->observePaperFinancialPosition(
            'paper:financial-position:observe:01K9ABCDEFGHJKMNPQRSTVWXY1:1:v1',
            ['schema_version' => 1],
        );

        $this->assertSame('HOLD', $result['decision']);
        $this->assertNull($result['settlementId']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://engine.test/v1/commands/paper-financial-positions/observations'
            && $request->hasHeader(
                'Idempotency-Key',
                'paper:financial-position:observe:01K9ABCDEFGHJKMNPQRSTVWXY1:1:v1',
            )
            && $request->hasHeader('Authorization', 'Bearer test-service-assertion'));
    }

    public function test_paper_financial_observation_rejects_inconsistent_exit_settlement_identity(): void
    {
        $client = $this->clientWithoutSodium();
        config()->set('services.trading_engine.paper_financial_lifecycle_enabled', true);
        Http::preventStrayRequests();
        Http::fake([
            'https://engine.test/v1/commands/paper-financial-positions/observations' => fn (Request $request): PromiseInterface => $this->responseFor($request, [
                'operationId' => '01K9ABCDEFGHJKMNPQRSTVWXYZ',
                'positionId' => '01K9ABCDEFGHJKMNPQRSTVWXY1',
                'decisionId' => '01K9ABCDEFGHJKMNPQRSTVWXY2',
                'eventId' => '01K9ABCDEFGHJKMNPQRSTVWXY3',
                'settlementId' => null,
                'decision' => 'EXIT',
                'duplicate' => false,
            ], 202),
        ]);

        try {
            $client->observePaperFinancialPosition(
                'paper:financial-position:observe:01K9ABCDEFGHJKMNPQRSTVWXY1:1:v1',
                ['schema_version' => 1],
            );
            $this->fail('An EXIT response without settlement identity was accepted.');
        } catch (TradingEngineException $exception) {
            $this->assertSame('INVALID_RESPONSE', $exception->errorCode);
            $this->assertSame(202, $exception->responseStatus);
        }
    }

    public function test_paper_observation_error_exposes_only_safe_structured_diagnostics(): void
    {
        $client = $this->clientWithoutSodium();
        config()->set([
            'services.trading_engine.paper_lifecycle_integration_enabled' => true,
            'services.trading_engine.paper_lifecycle_authoritative_enabled' => true,
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://engine.test/v1/commands/paper-positions/observations' => Http::response([
                'error' => [
                    'code' => 'PAPER_OBSERVATION_OUT_OF_ORDER',
                    'message' => 'remote-secret-value',
                    'retryable' => false,
                ],
            ], 409),
        ]);

        try {
            $client->observePaperPosition('paper:position:observe:laravel:99:171:v1', []);
            $this->fail('A conflicting PAPER observation was accepted.');
        } catch (TradingEngineException $exception) {
            $this->assertSame('REQUEST_CONFLICT', $exception->errorCode);
            $this->assertSame(409, $exception->responseStatus);
            $this->assertSame('PAPER_OBSERVATION_OUT_OF_ORDER', $exception->engineErrorCode);
            $this->assertStringNotContainsString('remote-secret-value', $exception->getMessage());
        }
    }

    public function test_paper_observation_discards_unsafe_remote_error_codes(): void
    {
        $client = $this->clientWithoutSodium();
        config()->set([
            'services.trading_engine.paper_lifecycle_integration_enabled' => true,
            'services.trading_engine.paper_lifecycle_authoritative_enabled' => true,
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://engine.test/v1/commands/paper-positions/observations' => Http::response([
                'error' => [
                    'code' => "UNSAFE\nremote-secret-value",
                    'retryable' => false,
                ],
            ], 409),
        ]);

        try {
            $client->observePaperPosition('paper:position:observe:laravel:99:171:v1', []);
            $this->fail('An unsafe remote error response was accepted.');
        } catch (TradingEngineException $exception) {
            $this->assertSame('REQUEST_CONFLICT', $exception->errorCode);
            $this->assertNull($exception->engineErrorCode);
            $this->assertStringNotContainsString('remote-secret-value', $exception->getMessage());
        }
    }

    public function test_invalid_trace_inputs_are_replaced_before_transmission(): void
    {
        $captured = null;
        Http::preventStrayRequests();
        Http::fake([
            'https://engine.test/v1/health/live' => function (Request $request) use (&$captured): PromiseInterface {
                $captured = $request;

                return $this->responseFor($request, $this->liveHealth());
            },
        ]);

        app(TradingEngineClient::class)->liveness("unsafe\r\nheader", 'invalid-traceparent');

        $this->assertInstanceOf(Request::class, $captured);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._:-]{1,128}$/', $captured->header('X-Correlation-Id')[0]);
        $this->assertMatchesRegularExpression('/^00-[0-9a-f]{32}-[0-9a-f]{16}-0[01]$/', $captured->header('traceparent')[0]);
    }

    private function clientWithoutSodium(): TradingEngineClient
    {
        $assertions = $this->mock(TradingEngineServiceAssertionFactory::class, function (MockInterface $mock): void {
            $mock->shouldReceive('create')->andReturn('test-service-assertion');
        });

        return new TradingEngineClient($assertions);
    }

    /** @param array<string, mixed> $body */
    private function responseFor(Request $request, array $body, int $status = 200): PromiseInterface
    {
        return Http::response($body, $status, [
            'X-Correlation-Id' => $request->header('X-Correlation-Id')[0] ?? '',
            'traceparent' => $request->header('traceparent')[0] ?? '',
        ]);
    }

    /** @return array{status: string, service: string, version: string, timestamp: string} */
    private function liveHealth(): array
    {
        return [
            'status' => 'ok',
            'service' => 'meme-scanner-trading-engine',
            'version' => '0.1.0',
            'timestamp' => '2026-09-27T12:00:00.000Z',
        ];
    }

    /** @return array<string, mixed> */
    private function readiness(string $status = 'ok', string $postgres = 'up', string $redis = 'not_required'): array
    {
        return [
            ...$this->liveHealth(),
            'status' => $status,
            'dependencies' => [
                'postgres' => $postgres,
                'redis' => $redis,
            ],
        ];
    }

    /** @return array{operationId: string, eventId: string, status: string, duplicate: bool} */
    private function noopResponse(bool $duplicate = false): array
    {
        return [
            'operationId' => '01K9ABCDEFGHJKMNPQRSTVWXYZ',
            'eventId' => '01K9ZYXWVTSRQPNMKJHGFEDCBA',
            'status' => 'accepted',
            'duplicate' => $duplicate,
        ];
    }

    /** @return array<string, mixed> */
    private function payload(string $token): array
    {
        $parts = explode('.', $token);
        $payload = strtr($parts[1], '-_', '+/');
        $payload .= str_repeat('=', (4 - mb_strlen($payload, '8bit') % 4) % 4);

        return json_decode((string) base64_decode($payload, true), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array{purpose: string, private_key_base64: string, public_key_base64: string, public_key_spki_base64: string} */
    private function keyFixture(): array
    {
        return json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/trading-engine-service-key.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
