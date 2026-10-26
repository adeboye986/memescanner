<?php

namespace App\Jobs;

use App\Exceptions\TradingEngineException;
use App\Models\TradingEngineScanRequest;
use App\Services\TradingEngine\TradingEngineCanonicalJson;
use App\Services\TradingEngine\TradingEngineClient;
use App\Services\TradingEngine\TradingEngineScanDeliveryTracker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SubmitTradingEngineTokenScan implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 45;

    public bool $failOnTimeout = false;

    private ?int $claimedAttempt = null;

    public function __construct(public readonly int $scanRequestId) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [5, 30, 120, 300];
    }

    public function handle(
        TradingEngineClient $client,
        TradingEngineCanonicalJson $canonicalJson,
        TradingEngineScanDeliveryTracker $deliveries,
    ): void {
        if (! $this->enabled()) {
            return;
        }

        $request = TradingEngineScanRequest::query()->find($this->scanRequestId);

        if (! $request instanceof TradingEngineScanRequest
            || ! is_array($request->payload)
            || $canonicalJson->hash($request->payload) !== $request->payload_sha256) {
            return;
        }

        $attempt = $deliveries->beginAttempt($request);

        if ($attempt === null) {
            return;
        }

        $this->claimedAttempt = $attempt;

        try {
            $response = $client->startTokenScan($request->idempotency_key, $request->payload);
            $deliveries->acknowledged($request, $response, $attempt);
        } catch (TradingEngineException $exception) {
            if (! $exception->retryable) {
                if ($this->conclusivelyRejected($exception)) {
                    $deliveries->rejected($request, $exception->errorCode, $attempt);
                } else {
                    $deliveries->acceptanceUnknown($request, $exception->errorCode, $attempt);
                }

                $this->fail($exception);

                return;
            }

            $deliveries->retrying($request, $exception->errorCode, $attempt);

            throw $exception;
        } catch (Throwable $exception) {
            $deliveries->retrying($request, 'UNEXPECTED_FAILURE', $attempt);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $request = TradingEngineScanRequest::query()->find($this->scanRequestId);

        if (! $request instanceof TradingEngineScanRequest) {
            return;
        }

        $code = $exception instanceof TradingEngineException ? $exception->errorCode : 'JOB_FAILED';
        app(TradingEngineScanDeliveryTracker::class)
            ->acceptanceUnknown($request, $code, $this->claimedAttempt);
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
            && config('services.trading_engine.scanner_integration_enabled', false) === true;
    }
}
