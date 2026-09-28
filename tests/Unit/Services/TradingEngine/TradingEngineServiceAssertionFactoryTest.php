<?php

namespace Tests\Unit\Services\TradingEngine;

use App\Exceptions\TradingEngineException;
use App\Services\TradingEngine\TradingEngineServiceAssertionFactory;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TradingEngineServiceAssertionFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $fixture = $this->keyFixture();
        config()->set('services.trading_engine.service_issuer', 'laravel-test');
        config()->set('services.trading_engine.service_audience', 'engine-test');
        config()->set('services.trading_engine.service_subject', 'laravel-service');
        config()->set('services.trading_engine.private_key_base64', $fixture['private_key_base64']);
        config()->set('services.trading_engine.assertion_lifetime_seconds', 30);
    }

    public function test_creates_a_short_lived_eddsa_assertion_with_required_claims(): void
    {
        $this->travelTo('2026-09-27 12:00:00 UTC');
        JWT::$timestamp = now()->getTimestamp();
        $headers = new \stdClass;

        try {
            $token = app(TradingEngineServiceAssertionFactory::class)->create([
                'commands:noop',
                'health:read',
                'health:read',
            ]);
            $claims = JWT::decode(
                $token,
                new Key($this->keyFixture()['public_key_base64'], 'EdDSA'),
                $headers,
            );
        } finally {
            JWT::$timestamp = null;
        }

        $this->assertSame('EdDSA', $headers->alg);
        $this->assertSame('JWT', $headers->typ);
        $this->assertSame('laravel-test', $claims->iss);
        $this->assertSame('engine-test', $claims->aud);
        $this->assertSame('laravel-service', $claims->sub);
        $this->assertSame('commands:noop health:read', $claims->scope);
        $this->assertSame(1790510400, $claims->iat);
        $this->assertSame(1790510430, $claims->exp);
        $this->assertTrue(Str::isUuid($claims->jti));
    }

    public function test_creates_a_unique_jti_for_each_assertion(): void
    {
        $first = $this->payload(app(TradingEngineServiceAssertionFactory::class)->create(['health:read']));
        $second = $this->payload(app(TradingEngineServiceAssertionFactory::class)->create(['health:read']));

        $this->assertNotSame($first['jti'], $second['jti']);
    }

    public function test_rejects_a_missing_private_key_without_exposing_key_material(): void
    {
        config()->set('services.trading_engine.private_key_base64');

        try {
            app(TradingEngineServiceAssertionFactory::class)->create(['health:read']);
            $this->fail('A missing private key was accepted.');
        } catch (TradingEngineException $exception) {
            $this->assertSame('CONFIGURATION_INVALID', $exception->errorCode);
            $this->assertStringNotContainsString($this->keyFixture()['private_key_base64'], $exception->getMessage());
        }
    }

    public function test_rejects_invalid_private_key_material(): void
    {
        config()->set('services.trading_engine.private_key_base64', base64_encode('not-an-ed25519-secret-key'));

        $this->expectException(TradingEngineException::class);
        $this->expectExceptionMessage('The trading engine Ed25519 private key is invalid.');

        app(TradingEngineServiceAssertionFactory::class)->create(['health:read']);
    }

    public function test_php_assertion_verifies_with_the_engine_jose_runtime(): void
    {
        $node = (new ExecutableFinder)->find('node');

        if ($node === null) {
            $this->markTestSkipped('Node.js is unavailable for the cross-runtime compatibility check.');
        }

        $token = app(TradingEngineServiceAssertionFactory::class)->create(['health:read']);
        $fixture = $this->keyFixture();
        $script = <<<'JAVASCRIPT'
import { createPublicKey } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { jwtVerify } from 'jose';

const input = JSON.parse(readFileSync(0, 'utf8'));
const publicKey = createPublicKey({
  key: Buffer.from(input.public_key_spki_base64, 'base64'),
  format: 'der',
  type: 'spki',
});
const verified = await jwtVerify(input.token, publicKey, {
  algorithms: ['EdDSA'],
  issuer: 'laravel-test',
  audience: 'engine-test',
  requiredClaims: ['iat', 'exp', 'jti', 'sub'],
});
process.stdout.write(JSON.stringify(verified.payload));
JAVASCRIPT;
        $process = new Process([$node, '--input-type=module', '--eval', $script], base_path('trading-engine'));
        $process->setInput(json_encode([
            'token' => $token,
            'public_key_spki_base64' => $fixture['public_key_spki_base64'],
        ], JSON_THROW_ON_ERROR));

        $process->mustRun();
        $claims = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('laravel-test', $claims['iss']);
        $this->assertSame('engine-test', $claims['aud']);
        $this->assertSame('laravel-service', $claims['sub']);
        $this->assertSame('health:read', $claims['scope']);
    }

    public function test_key_fixture_is_unmistakably_marked_as_public_test_material(): void
    {
        $this->assertSame(
            'TEST ONLY - PUBLIC FIXTURE - NEVER USE IN PRODUCTION',
            $this->keyFixture()['purpose'],
        );
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
