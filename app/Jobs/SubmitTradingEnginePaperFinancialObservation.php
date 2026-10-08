<?php

namespace App\Jobs;

use App\Exceptions\TradingEngineException;
use App\Models\TradingEnginePaperFinancialObservation;
use App\Services\TradingEngine\TradingEngineCanonicalJson;
use App\Services\TradingEngine\TradingEngineClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use LogicException;

class SubmitTradingEnginePaperFinancialObservation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $observationId) {}

    public function uniqueId(): string
    {
        return 'trading-engine-paper-financial-observation:'.$this->observationId;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [5, 30, 120, 300];
    }

    public function handle(
        TradingEngineClient $client,
        TradingEngineCanonicalJson $canonicalJson,
    ): void {
        if (! $this->enabled()) {
            return;
        }

        $observation = TradingEnginePaperFinancialObservation::query()->find($this->observationId);

        if (! $observation instanceof TradingEnginePaperFinancialObservation
            || ! in_array($observation->status, ['pending', 'submitted'], true)
            || ! is_array($observation->payload)
            || $canonicalJson->hash($observation->payload) !== $observation->payload_sha256) {
            return;
        }

        try {
            $response = $client->observePaperFinancialPosition(
                $observation->idempotency_key,
                $observation->payload,
            );
            DB::transaction(function () use ($response): void {
                $current = TradingEnginePaperFinancialObservation::query()
                    ->lockForUpdate()
                    ->find($this->observationId);

                if (! $current instanceof TradingEnginePaperFinancialObservation) {
                    throw new LogicException('The PAPER financial observation disappeared before response persistence.');
                }

                $identifiers = [
                    'engine_operation_id' => $response['operationId'],
                    'engine_decision_id' => $response['decisionId'],
                    'engine_event_id' => $response['eventId'],
                    'engine_settlement_id' => $response['settlementId'],
                    'engine_decision' => $response['decision'],
                ];

                foreach ($identifiers as $column => $value) {
                    if ($current->{$column} !== null && $current->{$column} !== $value) {
                        throw new LogicException('The PAPER financial observation response conflicts with persisted engine identity.');
                    }
                }

                $current->forceFill([
                    ...$identifiers,
                    'status' => $current->status === 'projected' ? 'projected' : 'submitted',
                    'last_error_code' => null,
                    'submitted_at' => now(),
                ])->save();
            }, 3);
        } catch (TradingEngineException $exception) {
            $observation->forceFill([
                'status' => $exception->retryable ? 'pending' : 'failed',
                'last_error_code' => $exception->errorCode,
            ])->save();

            if (! $exception->retryable) {
                $this->fail($exception);

                return;
            }

            throw $exception;
        }
    }

    private function enabled(): bool
    {
        return config('services.trading_engine.enabled', false) === true
            && config('services.trading_engine.paper_financial_lifecycle_enabled', false) === true;
    }
}
