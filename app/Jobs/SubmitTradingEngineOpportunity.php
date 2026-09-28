<?php

namespace App\Jobs;

use App\Exceptions\TradingEngineException;
use App\Services\TradingEngine\TradingEngineClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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

    public function handle(TradingEngineClient $client): void
    {
        if (config('services.trading_engine.enabled', false) !== true
            || config('services.trading_engine.opportunity_export_enabled', false) !== true) {
            return;
        }

        try {
            $client->recordOpportunity($this->idempotencyKey, $this->payload);
        } catch (TradingEngineException $exception) {
            if (! $exception->retryable) {
                $this->fail($exception);

                return;
            }

            throw $exception;
        }
    }
}
