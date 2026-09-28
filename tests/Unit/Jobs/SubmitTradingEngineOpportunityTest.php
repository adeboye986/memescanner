<?php

namespace Tests\Unit\Jobs;

use App\Jobs\SubmitTradingEngineOpportunity;
use App\Services\TradingEngine\TradingEngineClient;
use App\Services\TradingEngine\TradingEngineServiceAssertionFactory;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SubmitTradingEngineOpportunityTest extends TestCase
{
    public function test_repeated_attempts_keep_the_immutable_body_and_key_but_use_fresh_jwts(): void
    {
        $assertions = $this->mock(TradingEngineServiceAssertionFactory::class);
        $assertions->shouldReceive('create')
            ->twice()
            ->with(['commands:opportunities:create'])
            ->andReturn('first.test.assertion', 'second.test.assertion');
        $fixture = $this->keyFixture();
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
        $requests = [];
        Http::preventStrayRequests();
        Http::fake([
            'https://engine.test/v1/commands/opportunities' => function (Request $request) use (&$requests): PromiseInterface {
                $requests[] = $request;

                return Http::response([
                    'operationId' => '01K9ABCDEFGHJKMNPQRSTVWXYZ',
                    'opportunityId' => '01K9ZYXWVTSRQPNMKJHGFEDCBA',
                    'eventId' => '01K9ZYXWVTSRQPNMKJHGFEDCBB',
                    'status' => 'accepted',
                    'duplicate' => count($requests) > 1,
                ], 202, [
                    'X-Correlation-Id' => $request->header('X-Correlation-Id')[0] ?? '',
                    'traceparent' => $request->header('traceparent')[0] ?? '',
                ]);
            },
        ]);
        $payload = ['schema_version' => 1, 'source' => ['opportunity_id' => '42']];
        $job = new SubmitTradingEngineOpportunity($payload, 'opportunity:record:laravel:42:v1');
        $client = app(TradingEngineClient::class);

        $job->handle($client);
        $job->handle($client);

        $this->assertCount(2, $requests);
        $this->assertSame($requests[0]->body(), $requests[1]->body());
        $this->assertSame($requests[0]->header('Idempotency-Key'), $requests[1]->header('Idempotency-Key'));
        $this->assertNotSame($requests[0]->header('Authorization'), $requests[1]->header('Authorization'));
        $this->assertSame($payload, $job->payload);
        $this->assertSame('opportunity:record:laravel:42:v1', $job->idempotencyKey);
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
