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
            throw $this->invalidResponse($response->status());
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
            throw $this->invalidResponse($response->status());
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
            throw $this->invalidResponse($response->status());
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
            throw $this->invalidResponse($response->status());
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
        $this->requireResponseContext($response, $correlationId, $traceparent, true);
        $result = $response->json();

        if (! is_array($result)
            || ! $this->hasExactKeys($result, ['operationId', 'opportunityId', 'eventId', 'status', 'duplicate'])
            || ! $this->validEngineId($result['operationId'] ?? null)
            || ! $this->validEngineId($result['opportunityId'] ?? null)
            || ! $this->validEngineId($result['eventId'] ?? null)
            || $result['status'] !== 'accepted'
            || ! is_bool($result['duplicate'])) {
            throw $this->invalidResponse($response->status(), true);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{operationId: string, scanExecutionId: string, status: string, duplicate: bool}
     */
    public function startTokenScan(string $idempotencyKey, array $payload): array
    {
        if (config('services.trading_engine.scanner_integration_enabled', false) !== true) {
            throw new TradingEngineException('INTEGRATION_DISABLED', 'Trading engine scanner integration is disabled.');
        }

        [$response, $correlationId, $traceparent] = $this->request(
            'POST',
            '/v1/commands/token-scans',
            'commands:token-scans:create',
            $payload,
            ['Idempotency-Key' => $idempotencyKey],
        );
        $this->requireStatus($response, [202]);
        $this->requireResponseContext($response, $correlationId, $traceparent, true);
        $result = $response->json();

        if (! is_array($result)
            || ! $this->hasExactKeys($result, ['operationId', 'scanExecutionId', 'status', 'duplicate'])
            || ! $this->validEngineId($result['operationId'] ?? null)
            || ! $this->validEngineId($result['scanExecutionId'] ?? null)
            || $result['status'] !== 'accepted'
            || ! is_bool($result['duplicate'])) {
            throw $this->invalidResponse($response->status(), true);
        }

        return $result;
    }

    /**
     * @return array{
     *     schema_version: int,
     *     scan_execution_id: string,
     *     control_plane_user_id: string,
     *     candidates: list<array<string, mixed>>,
     *     pagination: array{page: int, per_page: int, total: int, last_page: int}
     * }
     */
    public function tokenScanCandidates(
        string $scanExecutionId,
        int|string $controlPlaneUserId,
        int $page = 1,
        int $perPage = 20,
    ): array {
        if (config('services.trading_engine.scanner_integration_enabled', false) !== true) {
            throw new TradingEngineException('INTEGRATION_DISABLED', 'Trading engine scanner integration is disabled.');
        }

        $controlPlaneUserId = (string) $controlPlaneUserId;
        if (! $this->validEngineId($scanExecutionId)
            || preg_match('/^[1-9][0-9]*$/D', $controlPlaneUserId) !== 1
            || $page < 1
            || $page > 10000
            || $perPage < 1
            || $perPage > 50) {
            throw new TradingEngineException('VALIDATION_FAILED', 'The token scan candidate request is invalid.');
        }

        $query = http_build_query([
            'control_plane_user_id' => $controlPlaneUserId,
            'page' => $page,
            'per_page' => $perPage,
        ], '', '&', PHP_QUERY_RFC3986);
        [$response, $correlationId, $traceparent] = $this->request(
            'GET',
            '/v1/token-scans/'.rawurlencode($scanExecutionId).'/candidates?'.$query,
            'token-scans:candidates:read',
        );
        $this->requireStatus($response, [200]);
        $this->requireResponseContext($response, $correlationId, $traceparent);
        $result = $response->json();

        if (! $this->validTokenScanCandidateResponse(
            $result,
            $scanExecutionId,
            $controlPlaneUserId,
            $page,
            $perPage,
        )) {
            throw $this->invalidResponse($response->status());
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{operationId: string, walletId: string, intentId: string, orderId: string, fillId: string, positionId: string, eventId: string, status: string, duplicate: bool}
     */
    public function executePaperEntry(string $idempotencyKey, array $payload): array
    {
        $this->ensurePaperEntryEnabled();

        [$response, $correlationId, $traceparent] = $this->request(
            'POST',
            '/v1/commands/paper-entries',
            'commands:paper-entries:create',
            $payload,
            ['Idempotency-Key' => $idempotencyKey],
        );
        $this->requireStatus($response, [202]);
        $this->requireResponseContext($response, $correlationId, $traceparent);
        $result = $response->json();
        $keys = ['operationId', 'walletId', 'intentId', 'orderId', 'fillId', 'positionId', 'eventId', 'status', 'duplicate'];

        if (! is_array($result)
            || ! $this->hasExactKeys($result, $keys)
            || ! $this->validEngineId($result['operationId'] ?? null)
            || ! $this->validEngineId($result['walletId'] ?? null)
            || ! $this->validEngineId($result['intentId'] ?? null)
            || ! $this->validEngineId($result['orderId'] ?? null)
            || ! $this->validEngineId($result['fillId'] ?? null)
            || ! $this->validEngineId($result['positionId'] ?? null)
            || ! $this->validEngineId($result['eventId'] ?? null)
            || $result['status'] !== 'accepted'
            || ! is_bool($result['duplicate'])) {
            throw $this->invalidResponse($response->status());
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{operationId: string, positionId: string, eventId: string, status: string, duplicate: bool}
     */
    public function recordPaperPosition(string $idempotencyKey, array $payload): array
    {
        $this->ensurePaperLifecycleEnabled();

        [$response, $correlationId, $traceparent] = $this->request(
            'POST',
            '/v1/commands/paper-positions',
            'commands:paper-positions:create',
            $payload,
            ['Idempotency-Key' => $idempotencyKey],
        );
        $this->requireStatus($response, [202]);
        $this->requireResponseContext($response, $correlationId, $traceparent);
        $result = $response->json();

        if (! is_array($result)
            || ! $this->hasExactKeys($result, ['operationId', 'positionId', 'eventId', 'status', 'duplicate'])
            || ! $this->validEngineId($result['operationId'] ?? null)
            || ! $this->validEngineId($result['positionId'] ?? null)
            || ! $this->validEngineId($result['eventId'] ?? null)
            || $result['status'] !== 'accepted'
            || ! is_bool($result['duplicate'])) {
            throw $this->invalidResponse($response->status());
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{operationId: string, positionId: string, decisionId: string, eventId: string, decision: string, duplicate: bool}
     */
    public function observePaperPosition(
        string $idempotencyKey,
        array $payload,
        ?string $correlationId = null,
        ?string $traceparent = null,
    ): array {
        $this->ensurePaperLifecycleEnabled();

        [$response, $correlationId, $traceparent] = $this->request(
            'POST',
            '/v1/commands/paper-positions/observations',
            'commands:paper-positions:observe',
            $payload,
            ['Idempotency-Key' => $idempotencyKey],
            $correlationId,
            $traceparent,
        );
        $this->requireStatus($response, [202]);
        $this->requireResponseContext($response, $correlationId, $traceparent);
        $result = $response->json();

        if (! is_array($result)
            || ! $this->hasExactKeys($result, ['operationId', 'positionId', 'decisionId', 'eventId', 'decision', 'duplicate'])
            || ! $this->validEngineId($result['operationId'] ?? null)
            || ! $this->validEngineId($result['positionId'] ?? null)
            || ! $this->validEngineId($result['decisionId'] ?? null)
            || ! $this->validEngineId($result['eventId'] ?? null)
            || ! in_array($result['decision'], ['HOLD', 'EXIT'], true)
            || ! is_bool($result['duplicate'])) {
            throw $this->invalidResponse($response->status());
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{operationId: string, positionId: string, decisionId: string, eventId: string, settlementId: string|null, decision: string, duplicate: bool}
     */
    public function observePaperFinancialPosition(string $idempotencyKey, array $payload): array
    {
        $this->ensurePaperFinancialLifecycleEnabled();

        [$response, $correlationId, $traceparent] = $this->request(
            'POST',
            '/v1/commands/paper-financial-positions/observations',
            'commands:paper-financial-positions:observe',
            $payload,
            ['Idempotency-Key' => $idempotencyKey],
        );
        $this->requireStatus($response, [202]);
        $this->requireResponseContext($response, $correlationId, $traceparent);
        $result = $response->json();
        $keys = ['operationId', 'positionId', 'decisionId', 'eventId', 'settlementId', 'decision', 'duplicate'];

        if (! is_array($result)
            || ! $this->hasExactKeys($result, $keys)
            || ! $this->validEngineId($result['operationId'] ?? null)
            || ! $this->validEngineId($result['positionId'] ?? null)
            || ! $this->validEngineId($result['decisionId'] ?? null)
            || ! $this->validEngineId($result['eventId'] ?? null)
            || (! is_null($result['settlementId'] ?? null) && ! $this->validEngineId($result['settlementId']))
            || ! in_array($result['decision'], ['HOLD', 'EXIT'], true)
            || (($result['decision'] === 'EXIT')
                !== is_string($result['settlementId']))
            || ! is_bool($result['duplicate'])) {
            throw $this->invalidResponse($response->status());
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

    private function ensurePaperEntryEnabled(): void
    {
        $this->ensureEnabled();

        if (config('services.trading_engine.paper_entry_integration_enabled', false) !== true) {
            throw new TradingEngineException('INTEGRATION_DISABLED', 'Trading engine PAPER entry integration is disabled.');
        }
    }

    private function ensurePaperLifecycleEnabled(): void
    {
        if (config('services.trading_engine.paper_lifecycle_integration_enabled', false) !== true
            || config('services.trading_engine.paper_lifecycle_authoritative_enabled', false) !== true) {
            throw new TradingEngineException('INTEGRATION_DISABLED', 'Trading engine PAPER lifecycle integration is disabled.');
        }
    }

    private function ensurePaperFinancialLifecycleEnabled(): void
    {
        $this->ensureEnabled();

        if (config('services.trading_engine.paper_financial_lifecycle_enabled', false) !== true) {
            throw new TradingEngineException(
                'INTEGRATION_DISABLED',
                'Trading engine PAPER financial lifecycle integration is disabled.',
            );
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
        $engineCode = $this->safeEngineErrorCode(is_array($body) ? data_get($body, 'error.code') : null);
        $retryable = is_array($body) && is_bool(data_get($body, 'error.retryable'))
            ? (bool) data_get($body, 'error.retryable')
            : $response->serverError() || $response->status() === 429;

        if (in_array($engineCode, ['AUTH_REQUIRED', 'AUTH_INVALID', 'AUTH_SCOPE_DENIED', 'AUTH_REPLAYED'], true)
            || in_array($response->status(), [401, 403], true)) {
            throw new TradingEngineException('AUTHENTICATION_FAILED', 'Trading engine authentication failed.', false, $response->status(), $engineCode);
        }

        if ($engineCode === 'VALIDATION_FAILED' || in_array($response->status(), [400, 422], true)) {
            throw new TradingEngineException('VALIDATION_FAILED', 'The trading engine rejected the request.', false, $response->status(), $engineCode);
        }

        if ($response->status() === 503) {
            throw new TradingEngineException('DEPENDENCY_UNAVAILABLE', 'A trading engine dependency is unavailable.', true, 503, $engineCode);
        }

        if ($response->status() === 409) {
            throw new TradingEngineException('REQUEST_CONFLICT', 'The trading engine request conflicts with existing state.', $retryable, 409, $engineCode);
        }

        throw new TradingEngineException(
            $response->serverError() || $response->status() === 429 ? 'ENGINE_UNAVAILABLE' : 'UNEXPECTED_RESPONSE',
            $response->serverError() || $response->status() === 429
                ? 'The trading engine is unavailable.'
                : 'The trading engine returned an unexpected response.',
            $retryable,
            $response->status(),
            $engineCode,
        );
    }

    private function safeEngineErrorCode(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Z][A-Z0-9_]{0,127}$/D', $value) === 1
            ? $value
            : null;
    }

    private function requireResponseContext(
        Response $response,
        string $correlationId,
        string $traceparent,
        bool $ambiguous = false,
    ): void {
        if ($response->header('X-Correlation-Id') !== $correlationId
            || $response->header('traceparent') !== $traceparent) {
            throw $this->invalidResponse($response->status(), $ambiguous);
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
            && in_array($result['dependencies']['redis'], ['up', 'down', 'not_required'], true);
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

    private function validTokenScanCandidateResponse(
        mixed $result,
        string $scanExecutionId,
        string $controlPlaneUserId,
        int $page,
        int $perPage,
    ): bool {
        if (! is_array($result)
            || ! $this->hasExactKeys($result, ['schema_version', 'scan_execution_id', 'control_plane_user_id', 'candidates', 'pagination'])
            || $result['schema_version'] !== 1
            || $result['scan_execution_id'] !== $scanExecutionId
            || $result['control_plane_user_id'] !== $controlPlaneUserId
            || ! is_array($result['candidates'])
            || ! is_array($result['pagination'])
            || ! $this->hasExactKeys($result['pagination'], ['page', 'per_page', 'total', 'last_page'])
            || $result['pagination']['page'] !== $page
            || $result['pagination']['per_page'] !== $perPage
            || ! is_int($result['pagination']['total'])
            || $result['pagination']['total'] < 0
            || ! is_int($result['pagination']['last_page'])
            || $result['pagination']['last_page'] < 1
            || count($result['candidates']) > $perPage) {
            return false;
        }

        return collect($result['candidates'])->every(fn (mixed $candidate): bool => $this->validTokenScanCandidate($candidate));
    }

    private function validTokenScanCandidate(mixed $candidate): bool
    {
        if (! is_array($candidate)
            || ! $this->hasExactKeys($candidate, [
                'address', 'symbol', 'name', 'provider', 'state', 'reason_code',
                'market', 'security_status', 'opportunity_id', 'evaluated_at',
            ])
            || ! is_string($candidate['address'])
            || $candidate['address'] === ''
            || mb_strlen($candidate['address']) > 128
            || ! $this->nullableBoundedString($candidate['symbol'], 64)
            || ! $this->nullableBoundedString($candidate['name'], 255)
            || ! in_array($candidate['provider'], ['birdeye', 'dexscreener', 'geckoterminal'], true)
            || ! in_array($candidate['state'], [
                'discovered', 'skipped', 'qualified', 'qualified_downstream_failed',
                'opportunity_created', 'opportunity_existing', 'failed',
            ], true)
            || ! $this->nullableSafeCode($candidate['reason_code'])
            || ! in_array($candidate['security_status'], ['not_reported', 'unavailable', 'passed', 'failed'], true)
            || (! is_null($candidate['opportunity_id']) && ! $this->validEngineId($candidate['opportunity_id']))
            || ! $this->validTimestamp($candidate['evaluated_at'])) {
            return false;
        }

        if ($candidate['market'] === null) {
            return true;
        }

        if (! is_array($candidate['market'])
            || ! $this->hasExactKeys($candidate['market'], [
                'market_cap_usd', 'price_usd', 'liquidity_usd', 'volume_5m_usd', 'holders',
            ])) {
            return false;
        }

        foreach ($candidate['market'] as $value) {
            $isNumber = is_int($value) || is_float($value);

            if ($value !== null && (! $isNumber || ! is_finite((float) $value) || $value < 0)) {
                return false;
            }
        }

        return true;
    }

    private function nullableBoundedString(mixed $value, int $maxLength): bool
    {
        return $value === null || (is_string($value) && $value !== '' && mb_strlen($value) <= $maxLength);
    }

    private function nullableSafeCode(mixed $value): bool
    {
        return $value === null || (is_string($value) && preg_match('/^[A-Z][A-Z0-9_]{0,127}$/D', $value) === 1);
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

    private function invalidResponse(int $responseStatus, bool $retryable = false): TradingEngineException
    {
        return new TradingEngineException('INVALID_RESPONSE', 'The trading engine returned an invalid response.', $retryable, $responseStatus);
    }
}
