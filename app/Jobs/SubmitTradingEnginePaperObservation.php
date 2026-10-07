<?php

namespace App\Jobs;

use App\Exceptions\TradingEngineException;
use App\Models\TradingEnginePaperObservation;
use App\Services\TradingEngine\TradingEngineCanonicalJson;
use App\Services\TradingEngine\TradingEngineClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class SubmitTradingEnginePaperObservation implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    public ?string $recoveryToken = null;

    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly int $observationId,
        public readonly array $payload,
        public readonly string $idempotencyKey,
        ?string $recoveryToken = null,
    ) {
        $this->recoveryToken = $recoveryToken;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [5, 30, 120, 300];
    }

    public function handle(TradingEngineClient $client, TradingEngineCanonicalJson $canonicalJson): void
    {
        if (! $this->enabled()) {
            return;
        }

        $observation = TradingEnginePaperObservation::query()->find($this->observationId);

        if (! $observation instanceof TradingEnginePaperObservation
            || $observation->payload_sha256 !== $canonicalJson->hash($this->payload)
            || $observation->idempotency_key !== $this->idempotencyKey
            || ! $this->authorizedForSubmission($observation)) {
            return;
        }

        $correlationId = Str::uuid()->toString();
        $claimed = $this->submissionQuery()->increment('submission_attempt_count', 1, [
            'last_correlation_id' => $correlationId,
            'last_response_status' => null,
            'last_engine_error_code' => null,
            'last_attempted_at' => now(),
        ]);

        if ($claimed !== 1) {
            return;
        }

        try {
            $response = $client->observePaperPosition(
                $this->idempotencyKey,
                $this->payload,
                $correlationId,
            );
            $this->submissionQuery()->update([
                'status' => 'submitted',
                'engine_decision_id' => $response['decisionId'],
                'engine_decision' => $response['decision'],
                'error_code' => null,
                'last_response_status' => 202,
                'last_engine_error_code' => null,
                'submitted_at' => now(),
                'recovered_at' => $this->recoveryToken === null ? null : now(),
                'recovery_token' => null,
            ]);
        } catch (TradingEngineException $exception) {
            $attributes = [
                'error_code' => $exception->errorCode,
                'last_response_status' => $exception->responseStatus,
                'last_engine_error_code' => $this->safeEngineErrorCode($exception->engineErrorCode),
            ];

            if (! $exception->retryable) {
                $attributes['status'] = 'failed';
                $attributes['recovery_token'] = null;
            }

            $this->submissionQuery()->update($attributes);

            if (! $exception->retryable) {
                $this->fail($exception);

                return;
            }

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $errorCode = $exception instanceof TradingEngineException
            ? $exception->errorCode
            : 'OBSERVATION_SUBMISSION_EXHAUSTED';
        $responseStatus = $exception instanceof TradingEngineException
            ? $exception->responseStatus
            : null;
        $engineErrorCode = $exception instanceof TradingEngineException
            ? $this->safeEngineErrorCode($exception->engineErrorCode)
            : null;

        $this->submissionQuery()->update([
            'status' => 'failed',
            'error_code' => $errorCode,
            'last_response_status' => $responseStatus,
            'last_engine_error_code' => $engineErrorCode,
            'recovery_token' => null,
        ]);
    }

    private function authorizedForSubmission(TradingEnginePaperObservation $observation): bool
    {
        return $observation->status === 'pending'
            && ($this->recoveryToken === null
                ? $observation->recovery_token === null
                : hash_equals($this->recoveryToken, (string) $observation->recovery_token));
    }

    /** @return Builder<TradingEnginePaperObservation> */
    private function submissionQuery(): Builder
    {
        $query = TradingEnginePaperObservation::query()
            ->whereKey($this->observationId)
            ->where('status', 'pending');

        return $this->recoveryToken === null
            ? $query->whereNull('recovery_token')
            : $query->where('recovery_token', $this->recoveryToken);
    }

    private function safeEngineErrorCode(?string $errorCode): ?string
    {
        return is_string($errorCode) && preg_match('/^[A-Z][A-Z0-9_]{0,127}$/D', $errorCode) === 1
            ? $errorCode
            : null;
    }

    private function enabled(): bool
    {
        return config('services.trading_engine.enabled', false) === true
            && config('services.trading_engine.paper_lifecycle_integration_enabled', false) === true
            && config('services.trading_engine.paper_lifecycle_authoritative_enabled', false) === true;
    }
}
