<?php

namespace App\Services\TradingEngine;

use App\Exceptions\TradingEngineException;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class TradingEngineClient
{
    private const CORRELATION_ID_PATTERN = '/^[A-Za-z0-9._:-]{1,128}$/';

    private const TRACEPARENT_PATTERN = '/^00-[0-9a-f]{32}-[0-9a-f]{16}-0[01]$/';

    public function __construct(private TradingEngineServiceAssertionFactory $assertions) {}

    /** @return array{status: string, service: string, version: string, timestamp: string} */
    public function liveness(?string $correlationId = null, ?string $traceparent = null): array
    {
        [$response, $correlationId, $traceparent] = $this->request(
            'GET',
            '/v1/health/live',
            'health:read',
            correlationId: $correlationId,
            traceparent: $traceparent,
        );

        $this->requireStatus($response, [200]);
        $this->requireResponseContext($response, $correlationId, $traceparent);
        $result = $response->json();

        if (! $this->validLiveHealth($result)) {
            throw $this->invalidResponse();
        }

        return $result;
    }

    /** @return array{status: string, service: string, version: string, timestamp: string, dependencies: array{postgres: string, redis: string}} */
    public function readiness(?string $correlationId = null, ?string $traceparent = null): array
    {
        [$response, $correlationId, $traceparent] = $this->request(
            'GET',
            '/v1/health/ready',
            'health:read',
            correlationId: $correlationId,
            traceparent: $traceparent,
        );

        $this->requireStatus($response, [200, 503]);
        $this->requireResponseContext($response, $correlationId, $traceparent);
        $result = $response->json();

        if (! $this->validReadiness($result, $response->status())) {
            throw $this->invalidResponse();
        }

        return $result;
    }

    /** @return array{service: string, version: string, node: string} */
    public function version(?string $correlationId = null, ?string $traceparent = null): array
    {
        [$response, $correlationId, $traceparent] = $this->request(
            'GET',
            '/v1/version',
            'health:read',
            correlationId: $correlationId,
            traceparent: $traceparent,
        );

        $this->requireStatus($response, [200]);
        $this->requireResponseContext($response, $correlationId, $traceparent);
        $result = $response->json();

        if (! is_array($result)
            || ! $this->hasExactKeys($result, ['service', 'version', 'node'])
            || ! $this->nonEmptyString($result['service'])
            || ! $this->nonEmptyString($result['version'])
            || ! $this->nonEmptyString($result['node'])) {
            throw $this->invalidResponse();
        }

        return $result;
    }

    /** @return array{operationId: string, eventId: string, status: string, duplicate: bool} */
    public function noop(
        string $idempotencyKey,
        ?string $message = null,
        ?string $correlationId = null,
        ?string $traceparent = null,
    ): array {
        if (preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $idempotencyKey) !== 1) {
            throw new TradingEngineException('VALIDATION_FAILED', 'The trading engine idempotency key is invalid.');
        }

        if ($message !== null && Str::length($message) > 256) {
            throw new TradingEngineException('VALIDATION_FAILED', 'The synthetic no-op message is too long.');
        }

        $body = $message === null ? [] : ['message' => $message];
        [$response, $correlationId, $traceparent] = $this->request(
            'POST',
            '/v1/commands/noop',
            'commands:noop',
            $body,
            ['Idempotency-Key' => $idempotencyKey],
            $correlationId,
            $traceparent,
        );

        $this->requireStatus($response, [202]);
        $this->requireResponseContext($response, $correlationId, $traceparent);
        $result = $response->json();

        if (! is_array($result)
            || ! $this->hasExactKeys($result, ['operationId', 'eventId', 'status', 'duplicate'])
            || ! is_string($result['operationId'])
            || mb_strlen($result['operationId']) !== 26
            || ! is_string($result['eventId'])
            || mb_strlen($result['eventId']) !== 26
            || $result['status'] !== 'accepted'
            || ! is_bool($result['duplicate'])) {
            throw $this->invalidResponse();
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{operationId: string, opportunityId: string, eventId: string, status: string, duplicate: bool}
     */
    public function recordOpportunity(
        string $idempotencyKey,
        array $payload,
        ?string $correlationId = null,
        ?string $traceparent = null,
    ): array {
        if (config('services.trading_engine.opportunity_export_enabled', false) !== true) {
            throw new TradingEngineException('INTEGRATION_DISABLED', 'Trading engine opportunity export is disabled.');
        }

        if (preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $idempotencyKey) !== 1) {
            throw new TradingEngineException('VALIDATION_FAILED', 'The trading engine idempotency key is invalid.');
        }

        [$response, $correlationId, $traceparent] = $this->request(
            'POST',
            '/v1/commands/opportunities',
            'commands:opportunities:create',
            $payload,
            ['Idempotency-Key' => $idempotencyKey],
            $correlationId,
            $traceparent,
        );

        $this->requireStatus($response, [202]);
        $this->requireResponseContext($response, $correlationId, $traceparent);
        $result = $response->json();

        if (! is_array($result)
            || ! $this->hasExactKeys($result, ['operationId', 'opportunityId', 'eventId', 'status', 'duplicate'])
            || ! $this->validEngineId($result['operationId'] ?? null)
            || ! $this->validEngineId($result['opportunityId'] ?? null)
            || ! $this->validEngineId($result['eventId'] ?? null)
            || $result['status'] !== 'accepted'
            || ! is_bool($result['duplicate'])) {
            throw $this->invalidResponse();
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     * @return array{Response, string, string}
     */
    private function request(
        string $method,
        string $path,
        string $scope,
        array $body = [],
        array $headers = [],
        ?string $correlationId = null,
        ?string $traceparent = null,
    ): array {
        $this->ensureEnabled();
        $baseUrl = $this->baseUrl();
        $correlationId = $this->safeCorrelationId($correlationId);
        $traceparent = $this->safeTraceparent($traceparent);

        try {
            $request = $this->pendingRequest($baseUrl, $scope)
                ->withHeaders([
                    'X-Correlation-Id' => $correlationId,
                    'traceparent' => $traceparent,
                    ...$headers,
                ]);

            $response = $method === 'GET'
                ? $request->get($path)
                : $request->post($path, $body);
        } catch (ConnectionException $exception) {
            $message = mb_strtolower($exception->getMessage());
            $timedOut = str_contains($message, 'timed out') || str_contains($message, 'timeout');

            throw new TradingEngineException(
                $timedOut ? 'TRANSPORT_TIMEOUT' : 'TRANSPORT_FAILURE',
                $timedOut ? 'The trading engine request timed out.' : 'The trading engine is unavailable.',
                true,
            );
        }

        return [$response, $correlationId, $traceparent];
    }

    private function pendingRequest(string $baseUrl, string $scope): PendingRequest
    {
        $connectTimeout = (int) config('services.trading_engine.connect_timeout_seconds', 3);
        $timeout = (int) config('services.trading_engine.timeout_seconds', 8);

        if ($connectTimeout < 1 || $timeout < $connectTimeout || $timeout > 60) {
            throw new TradingEngineException(
                'CONFIGURATION_INVALID',
                'Trading engine request timeouts are invalid.',
            );
        }

        return Http::baseUrl($baseUrl)
            ->acceptJson()
            ->asJson()
            ->withToken($this->assertions->create([$scope]))
            ->connectTimeout($connectTimeout)
            ->timeout($timeout)
            ->withoutRedirecting();
    }

    private function ensureEnabled(): void
    {
        if (! (bool) config('services.trading_engine.enabled', false)) {
            throw new TradingEngineException('INTEGRATION_DISABLED', 'Trading engine integration is disabled.');
        }
    }

    private function baseUrl(): string
    {
        $baseUrl = config('services.trading_engine.base_url');

        if (! is_string($baseUrl) || trim($baseUrl) === '') {
            throw new TradingEngineException('CONFIGURATION_INVALID', 'Trading engine base URL is not configured.');
        }

        $baseUrl = rtrim(trim($baseUrl), '/');
        $parts = parse_url($baseUrl);

        if (! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || ! in_array($parts['scheme'], ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '')) {
            throw new TradingEngineException('CONFIGURATION_INVALID', 'Trading engine base URL is invalid.');
        }

        if ($parts['scheme'] !== 'https' && ! app()->environment(['local', 'testing'])) {
            throw new TradingEngineException('CONFIGURATION_INVALID', 'Trading engine requires HTTPS outside local and testing environments.');
        }

        return $baseUrl;
    }

    /** @param array<int, int> $expectedStatuses */
    private function requireStatus(Response $response, array $expectedStatuses): void
    {
        if (in_array($response->status(), $expectedStatuses, true)) {
            return;
        }

        $body = $response->json();
        $engineCode = is_array($body) ? data_get($body, 'error.code') : null;
        $retryable = is_array($body) && is_bool(data_get($body, 'error.retryable'))
            ? (bool) data_get($body, 'error.retryable')
            : $response->serverError() || $response->status() === 429;

        if (in_array($engineCode, ['AUTH_REQUIRED', 'AUTH_INVALID', 'AUTH_SCOPE_DENIED', 'AUTH_REPLAYED'], true)
            || in_array($response->status(), [401, 403], true)) {
            throw new TradingEngineException('AUTHENTICATION_FAILED', 'Trading engine authentication failed.', false, $response->status());
        }

        if ($engineCode === 'VALIDATION_FAILED' || in_array($response->status(), [400, 422], true)) {
            throw new TradingEngineException('VALIDATION_FAILED', 'The trading engine rejected the request.', false, $response->status());
        }

        if ($response->status() === 503) {
            throw new TradingEngineException('DEPENDENCY_UNAVAILABLE', 'A trading engine dependency is unavailable.', true, 503);
        }

        if ($response->status() === 409) {
            throw new TradingEngineException('REQUEST_CONFLICT', 'The trading engine request conflicts with existing state.', $retryable, 409);
        }

        throw new TradingEngineException(
            $response->serverError() || $response->status() === 429 ? 'ENGINE_UNAVAILABLE' : 'UNEXPECTED_RESPONSE',
            $response->serverError() || $response->status() === 429
                ? 'The trading engine is unavailable.'
                : 'The trading engine returned an unexpected response.',
            $retryable,
            $response->status(),
        );
    }

    private function requireResponseContext(Response $response, string $correlationId, string $traceparent): void
    {
        if ($response->header('X-Correlation-Id') !== $correlationId
            || $response->header('traceparent') !== $traceparent) {
            throw $this->invalidResponse();
        }
    }

    private function validLiveHealth(mixed $result): bool
    {
        return is_array($result)
            && $this->hasExactKeys($result, ['status', 'service', 'version', 'timestamp'])
            && $result['status'] === 'ok'
            && $this->nonEmptyString($result['service'])
            && $this->nonEmptyString($result['version'])
            && $this->validTimestamp($result['timestamp']);
    }

    private function validReadiness(mixed $result, int $status): bool
    {
        return is_array($result)
            && $this->hasExactKeys($result, ['status', 'service', 'version', 'timestamp', 'dependencies'])
            && $result['status'] === ($status === 200 ? 'ok' : 'unavailable')
            && $this->nonEmptyString($result['service'])
            && $this->nonEmptyString($result['version'])
            && $this->validTimestamp($result['timestamp'])
            && is_array($result['dependencies'])
            && $this->hasExactKeys($result['dependencies'], ['postgres', 'redis'])
            && in_array($result['dependencies']['postgres'], ['up', 'down'], true)
            && in_array($result['dependencies']['redis'], ['up', 'down'], true);
    }

    private function validEngineId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $value) === 1;
    }

    private function validTimestamp(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i:s.v\Z',
            $value,
            new DateTimeZone('UTC'),
        );

        return $date instanceof DateTimeImmutable && $date->format('Y-m-d\TH:i:s.v\Z') === $value;
    }

    private function nonEmptyString(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  array<int, string>  $keys
     */
    private function hasExactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);

        return $actual === $keys;
    }

    private function safeCorrelationId(?string $correlationId): string
    {
        return is_string($correlationId) && preg_match(self::CORRELATION_ID_PATTERN, $correlationId) === 1
            ? $correlationId
            : Str::uuid()->toString();
    }

    private function safeTraceparent(?string $traceparent): string
    {
        return is_string($traceparent) && preg_match(self::TRACEPARENT_PATTERN, $traceparent) === 1
            ? $traceparent
            : '00-'.bin2hex(random_bytes(16)).'-'.bin2hex(random_bytes(8)).'-01';
    }

    private function invalidResponse(): TradingEngineException
    {
        return new TradingEngineException('INVALID_RESPONSE', 'The trading engine returned an invalid response.');
    }
}
