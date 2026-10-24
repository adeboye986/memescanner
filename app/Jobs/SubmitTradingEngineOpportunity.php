<?php

namespace App\Jobs;

use App\Exceptions\TradingEngineException;
use App\Models\TradingEngineOpportunityExport;
use App\Services\TradingEngine\TradingEngineClient;
use App\Services\TradingEngine\TradingEngineOpportunityExportTracker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SubmitTradingEngineOpportunity implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly array $payload,
        public readonly string $idempotencyKey,
    ) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [5, 30, 120, 300];
    }

    public function handle(
        TradingEngineClient $client,
        TradingEngineOpportunityExportTracker $exports,
    ): void {
        $export = $exports->forJob($this->payload, $this->idempotencyKey);

        if (config('services.trading_engine.enabled', false) !== true) {
            $exports->skippedJob($export, 'ENGINE_DISABLED');

            return;
        }

        if (config('services.trading_engine.opportunity_export_enabled', false) !== true) {
            $exports->skippedJob($export, 'EXPORT_DISABLED');

            return;
        }

        if (! $exports->beginAttempt($export)) {
            return;
        }

        try {
            $response = $client->recordOpportunity($this->idempotencyKey, $this->payload);
            $exports->acknowledged($export, $response);
        } catch (TradingEngineException $exception) {
            if (! $exception->retryable) {
                $exports->permanentlyFailed($export, $exception->errorCode);
                $this->fail($exception);

                return;
            }

            $exports->retrying($export, $exception->errorCode);

            throw $exception;
        } catch (Throwable $exception) {
            $exports->retrying($export, 'UNEXPECTED_FAILURE');

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $export = TradingEngineOpportunityExport::query()
            ->where('idempotency_key', $this->idempotencyKey)
            ->first();

        if (! $export || $export->status === TradingEngineOpportunityExport::STATUS_ACKNOWLEDGED) {
            return;
        }

        $errorCode = $exception instanceof TradingEngineException
            ? $exception->errorCode
            : 'JOB_FAILED';

        app(TradingEngineOpportunityExportTracker::class)->permanentlyFailed($export, $errorCode);
    }
}
