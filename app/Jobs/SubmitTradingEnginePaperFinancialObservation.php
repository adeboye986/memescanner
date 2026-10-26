<?php

namespace App\Jobs;

use App\Exceptions\TradingEngineException;
use App\Models\TradingEnginePaperFinancialObservation;
use App\Services\TradingEngine\TradingEngineCanonicalJson;
use App\Services\TradingEngine\TradingEngineClient;
use App\Services\TradingEngine\TradingEnginePaperDeliveryTracker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SubmitTradingEnginePaperFinancialObservation implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public bool $failOnTimeout = false;

    private ?int $claimedAttempt = null;

    public function __construct(public readonly int $observationId) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [5, 30, 120, 300];
    }

    public function handle(
        TradingEngineClient $client,
        TradingEngineCanonicalJson $canonicalJson,
        TradingEnginePaperDeliveryTracker $deliveries,
    ): void {
        if (! $this->enabled()) {
            return;
        }

        $observation = TradingEnginePaperFinancialObservation::query()->find($this->observationId);

        if (! $observation instanceof TradingEnginePaperFinancialObservation
            || ! is_array($observation->payload)
            || $canonicalJson->hash($observation->payload) !== $observation->payload_sha256) {
            return;
        }

        $attempt = $deliveries->beginObservationAttempt($observation);

        if ($attempt === null) {
            return;
        }

        $this->claimedAttempt = $attempt;

        try {
            $response = $client->observePaperFinancialPosition(
                $observation->idempotency_key,
                $observation->payload,
            );
            $deliveries->observationAcknowledged($observation, $response, $attempt);
        } catch (TradingEngineException $exception) {
            if (! $exception->retryable) {
                if ($this->conclusivelyRejected($exception)) {
                    $deliveries->observationRejected($observation, $exception->errorCode, $attempt);
                } else {
                    $deliveries->observationAcceptanceUnknown($observation, $exception->errorCode, $attempt);
                }

                $this->fail($exception);

                return;
            }

            $deliveries->observationRetrying($observation, $exception->errorCode, $attempt);

            throw $exception;
        } catch (Throwable $exception) {
            $deliveries->observationRetrying($observation, 'UNEXPECTED_FAILURE', $attempt);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $observation = TradingEnginePaperFinancialObservation::query()->find($this->observationId);

        if (! $observation instanceof TradingEnginePaperFinancialObservation) {
            return;
        }

        $errorCode = $exception instanceof TradingEngineException
            ? $exception->errorCode
            : 'JOB_FAILED';
        app(TradingEnginePaperDeliveryTracker::class)->observationAcceptanceUnknown(
            $observation,
            $errorCode,
            $this->claimedAttempt,
        );
    }

    private function conclusivelyRejected(TradingEngineException $exception): bool
    {
        return $exception->responseStatus !== null
            && in_array($exception->errorCode, [
                'AUTHENTICATION_FAILED',
                'REQUEST_CONFLICT',
                'VALIDATION_FAILED',
            ], true);
    }

    private function enabled(): bool
    {
        return config('services.trading_engine.enabled', false) === true
            && config('services.trading_engine.paper_financial_lifecycle_enabled', false) === true;
    }
}
