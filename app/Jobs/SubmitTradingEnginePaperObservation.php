<?php

namespace App\Jobs;

use App\Exceptions\TradingEngineException;
use App\Models\TradingEnginePaperObservation;
use App\Services\TradingEngine\TradingEngineCanonicalJson;
use App\Services\TradingEngine\TradingEngineClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SubmitTradingEnginePaperObservation implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly int $observationId,
        public readonly array $payload,
        public readonly string $idempotencyKey,
    ) {}

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
            || $observation->idempotency_key !== $this->idempotencyKey) {
            return;
        }

        try {
            $response = $client->observePaperPosition($this->idempotencyKey, $this->payload);
            TradingEnginePaperObservation::query()
                ->whereKey($observation->getKey())
                ->whereIn('status', ['pending', 'submitted'])
                ->update([
                    'status' => 'submitted',
                    'engine_decision_id' => $response['decisionId'],
                    'engine_decision' => $response['decision'],
                    'error_code' => null,
                    'submitted_at' => now(),
                ]);
        } catch (TradingEngineException $exception) {
            $observation->forceFill(['error_code' => $exception->errorCode])->save();

            if (! $exception->retryable) {
                $observation->forceFill(['status' => 'failed'])->save();
                $this->fail($exception);

                return;
            }

            throw $exception;
        }
    }

    private function enabled(): bool
    {
        return config('services.trading_engine.enabled', false) === true
            && config('services.trading_engine.paper_lifecycle_integration_enabled', false) === true
            && config('services.trading_engine.paper_lifecycle_authoritative_enabled', false) === true;
    }
}
