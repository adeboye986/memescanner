<?php

namespace App\Services\TradingEngine;

use App\Exceptions\TradingEngineException;
use Firebase\JWT\JWT;
use Illuminate\Support\Str;
use Throwable;

class TradingEngineServiceAssertionFactory
{
    /** @param array<int, string> $scopes */
    public function create(array $scopes): string
    {
        $normalizedScopes = $this->normalizeScopes($scopes);
        $issuer = $this->requiredString('service_issuer');
        $audience = $this->requiredString('service_audience');
        $subject = $this->requiredString('service_subject');
        $privateKey = $this->privateKey();
        $lifetime = (int) config('services.trading_engine.assertion_lifetime_seconds', 30);

        if ($lifetime < 1 || $lifetime > 300) {
            throw $this->invalidConfiguration('Trading engine assertion lifetime must be between 1 and 300 seconds.');
        }

        $issuedAt = now()->getTimestamp();

        try {
            return JWT::encode([
                'iss' => $issuer,
                'aud' => $audience,
                'sub' => $subject,
                'jti' => Str::uuid()->toString(),
                'iat' => $issuedAt,
                'exp' => $issuedAt + $lifetime,
                'scope' => implode(' ', $normalizedScopes),
            ], $privateKey, 'EdDSA');
        } catch (Throwable) {
            throw $this->invalidConfiguration('Trading engine service authentication could not be initialized.');
        }
    }

    /**
     * @param  array<int, string>  $scopes
     * @return array<int, string>
     */
    private function normalizeScopes(array $scopes): array
    {
        $normalized = [];

        foreach ($scopes as $scope) {
            if (preg_match('/^[a-z][a-z0-9:._-]*$/', $scope) !== 1) {
                throw $this->invalidConfiguration('Trading engine service assertion scopes are invalid.');
            }

            $normalized[$scope] = $scope;
        }

        if ($normalized === []) {
            throw $this->invalidConfiguration('A trading engine service assertion scope is required.');
        }

        ksort($normalized);

        return array_values($normalized);
    }

    private function privateKey(): string
    {
        if (! function_exists('sodium_crypto_sign_detached')) {
            throw $this->invalidConfiguration('The sodium PHP extension is required for trading engine authentication.');
        }

        $encoded = $this->requiredString('private_key_base64');
        $decoded = base64_decode($encoded, true);

        if (! is_string($decoded) || mb_strlen($decoded, '8bit') !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw $this->invalidConfiguration('The trading engine Ed25519 private key is invalid.');
        }

        return $encoded;
    }

    private function requiredString(string $key): string
    {
        $value = config("services.trading_engine.{$key}");

        if (! is_string($value) || trim($value) === '') {
            throw $this->invalidConfiguration("Trading engine {$key} is not configured.");
        }

        return trim($value);
    }

    private function invalidConfiguration(string $message): TradingEngineException
    {
        return new TradingEngineException('CONFIGURATION_INVALID', $message);
    }
}
