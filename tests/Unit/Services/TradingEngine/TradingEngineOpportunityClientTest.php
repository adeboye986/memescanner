<?php

namespace Tests\Unit\Services\TradingEngine;

use App\Exceptions\TradingEngineException;
use App\Services\TradingEngine\TradingEngineClient;
use App\Services\TradingEngine\TradingEngineServiceAssertionFactory;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TradingEngineOpportunityClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $fixture = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/trading-engine-service-key.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->mock(TradingEngineServiceAssertionFactory::class)
            ->shouldReceive('create')
            ->zeroOrMoreTimes()
            ->andReturnUsing(fn (): string => 'test.'.bin2hex(random_bytes(8)).'.assertion');
        config()->set([
            'services.trading_engine.enabled' => true,
            'services.trading_engine.opportunity_export_enabled' => true,
            'services.trading_engine.base_url' => 'https://engine.test',
            'services.trading_engine.service_issuer' => 'laravel-test',
            'services.trading_engine.service_audience' => 'engine-test',
            'services.trading_engine.service_subject' => 'laravel-service',
            'services.trading_engine.private_key_base64' => $fixture['private_key_base64'],
            'services.trading_engine.assertion_lifetime_seconds' => 30,
            'services.trading_engine.connect_timeout_seconds' => 3,
            'services.trading_engine.timeout_seconds' => 8,
        ]);
    }

    public function test_export_flag_blocks_direct_opportunity_requests_without_network_access(): void
    {
        config()->set('services.trading_engine.opportunity_export_enabled', false);
        Http::preventStrayRequests();

        try {
            app(TradingEngineClient::class)->recordOpportunity('opportunity:42', []);
            $this->fail('A disabled opportunity export made a request.');
        } catch (TradingEngineException $exception) {
            $this->assertSame('INTEGRATION_DISABLED', $exception->errorCode);
        }

        Http::assertNothingSent();
    }

    public function test_sends_exact_payload_key_and_scope_and_accepts_the_strict_response(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://engine.test/v1/commands/opportunities' => fn (Request $request): PromiseInterface => $this->responseFor($request, $this->response()),
        ]);
        $payload = ['schema_version' => 1, 'source' => ['opportunity_id' => '42']];

        $result = app(TradingEngineClient::class)->recordOpportunity(
            'opportunity:record:laravel:42:v1',
            $payload,
            'opportunity-correlation',
            '00-11111111111111111111111111111111-2222222222222222-01',
        );

        $this->assertSame($this->response(), $result);
        $this->mockedAssertionFactory()->shouldHaveReceived('create')
            ->with(['commands:opportunities:create'])
            ->once();
        Http::assertSent(function (Request $request) use ($payload): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://engine.test/v1/commands/opportunities'
                && $request->data() === $payload
                && $request->hasHeader('Idempotency-Key', 'opportunity:record:laravel:42:v1');
        });
    }

    #[DataProvider('invalidResponseProvider')]
    public function test_rejects_malformed_success_responses(array $response): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://engine.test/v1/commands/opportunities' => fn (Request $request): PromiseInterface => $this->responseFor($request, $response),
        ]);

        $this->expectException(TradingEngineException::class);
        $this->expectExceptionMessage('invalid response');

        app(TradingEngineClient::class)->recordOpportunity('opportunity:42', []);
    }

    public static function invalidResponseProvider(): array
    {
        $valid = [
            'operationId' => '01K9ABCDEFGHJKMNPQRSTVWXYZ',
            'opportunityId' => '01K9ZYXWVTSRQPNMKJHGFEDCBA',
            'eventId' => '01K9ZYXWVTSRQPNMKJHGFEDCBB',
            'status' => 'accepted',
            'duplicate' => false,
        ];

        return [
            'missing opportunity id' => [[...$valid, 'opportunityId' => null]],
            'invalid operation ULID' => [[...$valid, 'operationId' => str_repeat('I', 26)]],
            'extra response field' => [[...$valid, 'unexpected' => true]],
            'wrong status' => [[...$valid, 'status' => 'recorded']],
        ];
    }

    /** @param array<string, mixed> $body */
    private function responseFor(Request $request, array $body): PromiseInterface
    {
        return Http::response($body, 202, [
            'X-Correlation-Id' => $request->header('X-Correlation-Id')[0] ?? '',
            'traceparent' => $request->header('traceparent')[0] ?? '',
        ]);
    }

    /** @return array{operationId: string, opportunityId: string, eventId: string, status: string, duplicate: bool} */
    private function response(): array
    {
        return [
            'operationId' => '01K9ABCDEFGHJKMNPQRSTVWXYZ',
            'opportunityId' => '01K9ZYXWVTSRQPNMKJHGFEDCBA',
            'eventId' => '01K9ZYXWVTSRQPNMKJHGFEDCBB',
            'status' => 'accepted',
            'duplicate' => false,
        ];
    }

    private function mockedAssertionFactory(): TradingEngineServiceAssertionFactory
    {
        return app(TradingEngineServiceAssertionFactory::class);
    }
}
