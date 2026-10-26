<?php

namespace App\Jobs;

use App\Exceptions\TradingEngineException;
use App\Models\TradingEnginePaperEntryIntent;
use App\Services\TradingEngine\TradingEngineCanonicalJson;
use App\Services\TradingEngine\TradingEngineClient;
use App\Services\TradingEngine\TradingEnginePaperDeliveryTracker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SubmitTradingEnginePaperEntry implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public bool $failOnTimeout = false;

    private ?int $claimedAttempt = null;

    public function __construct(public readonly int $entryIntentId) {}

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

        $intent = TradingEnginePaperEntryIntent::query()->find($this->entryIntentId);

        if (! $intent instanceof TradingEnginePaperEntryIntent
            || ! is_array($intent->payload)
            || $canonicalJson->hash($intent->payload) !== $intent->payload_sha256) {
            return;
        }

        $attempt = $deliveries->beginEntryAttempt($intent);

        if ($attempt === null) {
            return;
        }

        $this->claimedAttempt = $attempt;

        try {
            $response = $client->executePaperEntry($intent->idempotency_key, $intent->payload);
            $deliveries->entryAcknowledged($intent, $response, $attempt);
        } catch (TradingEngineException $exception) {
            if (! $exception->retryable) {
                if ($this->conclusivelyRejected($exception)) {
                    $deliveries->entryRejected($intent, $exception->errorCode, $attempt);
                } else {
                    $deliveries->entryAcceptanceUnknown($intent, $exception->errorCode, $attempt);
                }

                $this->fail($exception);

                return;
            }

            $deliveries->entryRetrying($intent, $exception->errorCode, $attempt);

            throw $exception;
        } catch (Throwable $exception) {
            $deliveries->entryRetrying($intent, 'UNEXPECTED_FAILURE', $attempt);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $intent = TradingEnginePaperEntryIntent::query()->find($this->entryIntentId);

        if (! $intent instanceof TradingEnginePaperEntryIntent) {
            return;
        }

        $errorCode = $exception instanceof TradingEngineException
            ? $exception->errorCode
            : 'JOB_FAILED';
        app(TradingEnginePaperDeliveryTracker::class)->entryAcceptanceUnknown(
            $intent,
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
            && config('services.trading_engine.paper_entry_integration_enabled', false) === true;
    }
}
