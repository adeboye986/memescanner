<?php

namespace Tests\Unit\Services\TradingEngine;

use App\Exceptions\TradingEngineException;
use App\Services\TradingEngine\TradingEngineClient;
use App\Services\TradingEngine\TradingEngineServiceAssertionFactory;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class TradingEngineScanCandidateClientTest extends TestCase
{
    private const SCAN_ID = '01K9ABCDEFGHJKMNPQRSTVWXYZ';

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(TradingEngineServiceAssertionFactory::class, function (MockInterface $mock): void {
            $mock->shouldReceive('create')->andReturn('test.assertion');
        });
        config()->set([
            'services.trading_engine.enabled' => true,
            'services.trading_engine.scanner_integration_enabled' => true,
            'services.trading_engine.base_url' => 'https://engine.test',
            'services.trading_engine.connect_timeout_seconds' => 3,
            'services.trading_engine.timeout_seconds' => 8,
        ]);
    }

    public function test_requests_a_user_scoped_bounded_candidate_page_with_the_dedicated_scope(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://engine.test/v1/token-scans/*' => fn (Request $request): PromiseInterface => $this->responseFor($request, $this->validResponse()),
        ]);

        $result = app(TradingEngineClient::class)->tokenScanCandidates(self::SCAN_ID, 7, 2, 20);

        $this->assertSame(2, $result['pagination']['page']);
        app(TradingEngineServiceAssertionFactory::class)->shouldHaveReceived('create')
            ->with(['token-scans:candidates:read'])
            ->once();
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://engine.test/v1/token-scans/'.self::SCAN_ID.'/candidates?control_plane_user_id=7&page=2&per_page=20');
    }

    public function test_rejects_unexpected_or_raw_candidate_response_fields(): void
    {
        $response = $this->validResponse();
        $response['candidates'][0]['raw_provider_response'] = ['secret' => true];
        Http::fake([
            'https://engine.test/v1/token-scans/*' => fn (Request $request): PromiseInterface => $this->responseFor($request, $response),
        ]);

        $this->expectException(TradingEngineException::class);
        $this->expectExceptionMessage('invalid response');

        app(TradingEngineClient::class)->tokenScanCandidates(self::SCAN_ID, 7, 2, 20);
    }

    public function test_disabled_scanner_integration_prevents_candidate_network_access(): void
    {
        config()->set('services.trading_engine.scanner_integration_enabled', false);
        Http::preventStrayRequests();

        try {
            app(TradingEngineClient::class)->tokenScanCandidates(self::SCAN_ID, 7);
            $this->fail('Disabled scanner diagnostics made a request.');
        } catch (TradingEngineException $exception) {
            $this->assertSame('INTEGRATION_DISABLED', $exception->errorCode);
        }

        Http::assertNothingSent();
    }

    /** @return array<string, mixed> */
    private function validResponse(): array
    {
        return [
            'schema_version' => 1,
            'scan_execution_id' => self::SCAN_ID,
            'control_plane_user_id' => '7',
            'candidates' => [[
                'address' => 'SyntheticTokenAddress',
                'symbol' => 'SYN',
                'name' => 'Synthetic Token',
                'provider' => 'birdeye',
                'state' => 'failed',
                'reason_code' => 'PROVIDER_RATE_LIMITED',
                'market' => [
                    'market_cap_usd' => 10000.0,
                    'price_usd' => null,
                    'liquidity_usd' => 500.0,
                    'volume_5m_usd' => null,
                    'holders' => 42,
                ],
                'security_status' => 'unavailable',
                'opportunity_id' => null,
                'evaluated_at' => '2026-10-28T09:40:00.000Z',
            ]],
            'pagination' => ['page' => 2, 'per_page' => 20, 'total' => 21, 'last_page' => 2],
        ];
    }

    /** @param array<string, mixed> $body */
    private function responseFor(Request $request, array $body): PromiseInterface
    {
        return Http::response($body, 200, [
            'X-Correlation-Id' => $request->header('X-Correlation-Id')[0] ?? '',
            'traceparent' => $request->header('traceparent')[0] ?? '',
        ]);
    }
}
