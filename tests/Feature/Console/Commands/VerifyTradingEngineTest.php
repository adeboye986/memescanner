<?php

namespace Tests\Feature\Console\Commands;

use App\Services\TradingEngine\TradingEngineClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VerifyTradingEngineTest extends TestCase
{
    public function test_disabled_integration_fails_without_network_access(): void
    {
        config()->set('services.trading_engine.enabled', false);
        Http::preventStrayRequests();

        $this->artisan('trading-engine:verify')
            ->expectsOutputToContain('Trading engine integration is disabled.')
            ->expectsOutputToContain('INTEGRATION_DISABLED')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_health_verification_never_submits_a_noop_implicitly(): void
    {
        $client = $this->mock(TradingEngineClient::class);
        $client->shouldReceive('liveness')->once()->andReturn($this->liveness());
        $client->shouldReceive('readiness')->once()->andReturn($this->readiness());
        $client->shouldReceive('version')->once()->andReturn($this->version());
        $client->shouldNotReceive('noop');

        $this->artisan('trading-engine:verify')
            ->expectsOutputToContain('Liveness')
            ->expectsOutputToContain('Readiness')
            ->expectsOutputToContain('meme-scanner-trading-engine')
            ->assertSuccessful();
    }

    public function test_noop_requires_an_explicit_idempotency_key_before_any_http_request(): void
    {
        Http::preventStrayRequests();

        $this->artisan('trading-engine:verify', ['--noop' => true])
            ->expectsOutputToContain('The --idempotency-key option is required when using --noop.')
            ->expectsOutputToContain('VALIDATION_FAILED')
            ->assertFailed();

        Http::assertNothingSent();
    }

    #[DataProvider('invalidIdempotencyKeyProvider')]
    public function test_noop_rejects_invalid_idempotency_keys_before_any_http_request(string $idempotencyKey): void
    {
        Http::preventStrayRequests();

        $this->artisan('trading-engine:verify', [
            '--noop' => true,
            '--idempotency-key' => $idempotencyKey,
        ])
            ->expectsOutputToContain('must contain 1 to 128 letters, numbers, dots, underscores, colons, or hyphens')
            ->expectsOutputToContain('VALIDATION_FAILED')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public static function invalidIdempotencyKeyProvider(): array
    {
        return [
            'space' => ['unsafe key'],
            'slash' => ['unsafe/key'],
            'too long' => [str_repeat('a', 129)],
        ];
    }

    public function test_noop_accepts_zero_as_a_valid_explicit_idempotency_key(): void
    {
        $client = $this->healthyClientMock();
        $client->shouldReceive('noop')
            ->once()
            ->with('0', null)
            ->andReturn($this->noopResult());

        $this->artisan('trading-engine:verify', [
            '--noop' => true,
            '--idempotency-key' => '0',
        ])
            ->expectsOutputToContain('No-op idempotency key: 0')
            ->expectsOutputToContain('retry with the exact same --idempotency-key and --message value')
            ->assertSuccessful();
    }

    public function test_explicit_noop_option_uses_the_supplied_stable_idempotency_key(): void
    {
        $client = $this->healthyClientMock();
        $client->shouldReceive('noop')
            ->once()
            ->with('verify:retry-safe-1', 'synthetic command')
            ->andReturn($this->noopResult());

        $this->artisan('trading-engine:verify', [
            '--noop' => true,
            '--idempotency-key' => 'verify:retry-safe-1',
            '--message' => 'synthetic command',
        ])
            ->expectsOutputToContain('No-op idempotency key: verify:retry-safe-1')
            ->expectsOutputToContain('Synthetic no-op accepted.')
            ->assertSuccessful();
    }

    public function test_repeated_explicit_attempts_preserve_the_same_idempotency_key_and_message(): void
    {
        $client = $this->healthyClientMock(2);
        $client->shouldReceive('noop')
            ->twice()
            ->with('verify:uncertain-1', 'same synthetic command')
            ->andReturn(
                $this->noopResult(),
                $this->noopResult(duplicate: true),
            );
        $arguments = [
            '--noop' => true,
            '--idempotency-key' => 'verify:uncertain-1',
            '--message' => 'same synthetic command',
        ];

        $this->artisan('trading-engine:verify', $arguments)->assertSuccessful();
        $this->artisan('trading-engine:verify', $arguments)->assertSuccessful();
    }

    private function healthyClientMock(int $times = 1): TradingEngineClient
    {
        $client = $this->mock(TradingEngineClient::class);
        $client->shouldReceive('liveness')->times($times)->andReturn($this->liveness());
        $client->shouldReceive('readiness')->times($times)->andReturn($this->readiness());
        $client->shouldReceive('version')->times($times)->andReturn($this->version());

        return $client;
    }

    /** @return array{operationId: string, eventId: string, status: string, duplicate: bool} */
    private function noopResult(bool $duplicate = false): array
    {
        return [
            'operationId' => '01K9ABCDEFGHJKMNPQRSTVWXYZ',
            'eventId' => '01K9ZYXWVTSRQPNMKJHGFEDCBA',
            'status' => 'accepted',
            'duplicate' => $duplicate,
        ];
    }

    /** @return array{status: string, service: string, version: string, timestamp: string} */
    private function liveness(): array
    {
        return [
            'status' => 'ok',
            'service' => 'meme-scanner-trading-engine',
            'version' => '0.1.0',
            'timestamp' => '2026-09-27T12:00:00.000Z',
        ];
    }

    /** @return array{status: string, service: string, version: string, timestamp: string, dependencies: array{postgres: string, redis: string}} */
    private function readiness(): array
    {
        return [
            ...$this->liveness(),
            'dependencies' => [
                'postgres' => 'up',
                'redis' => 'up',
            ],
        ];
    }

    /** @return array{service: string, version: string, node: string} */
    private function version(): array
    {
        return [
            'service' => 'meme-scanner-trading-engine',
            'version' => '0.1.0',
            'node' => 'v24.21.0',
        ];
    }
}
