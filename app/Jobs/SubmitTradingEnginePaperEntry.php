<?php

namespace App\Jobs;

use App\Exceptions\TradingEngineException;
use App\Models\TradingEnginePaperEntryIntent;
use App\Services\TradingEngine\TradingEngineCanonicalJson;
use App\Services\TradingEngine\TradingEngineClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use LogicException;

class SubmitTradingEnginePaperEntry implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $entryIntentId) {}

    public function uniqueId(): string
    {
        return 'trading-engine-paper-entry:'.$this->entryIntentId;
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

        $intent = TradingEnginePaperEntryIntent::query()->find($this->entryIntentId);

        if (! $intent instanceof TradingEnginePaperEntryIntent
            || ! in_array($intent->status, ['pending', 'submitted'], true)
            || ! is_array($intent->payload)
            || $canonicalJson->hash($intent->payload) !== $intent->payload_sha256) {
            return;
        }

        try {
            $response = $client->executePaperEntry($intent->idempotency_key, $intent->payload);
            DB::transaction(function () use ($response): void {
                $current = TradingEnginePaperEntryIntent::query()
                    ->lockForUpdate()
                    ->find($this->entryIntentId);

                if (! $current instanceof TradingEnginePaperEntryIntent) {
                    throw new LogicException('The PAPER entry intent disappeared before response persistence.');
                }

                $identifiers = [
                    'engine_operation_id' => $response['operationId'],
                    'engine_wallet_id' => $response['walletId'],
                    'engine_intent_id' => $response['intentId'],
                    'engine_order_id' => $response['orderId'],
                    'engine_fill_id' => $response['fillId'],
                    'engine_position_id' => $response['positionId'],
                    'engine_event_id' => $response['eventId'],
                ];

                foreach ($identifiers as $column => $value) {
                    if ($current->{$column} !== null && $current->{$column} !== $value) {
                        throw new LogicException('The PAPER entry response conflicts with persisted engine identity.');
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
            $intent->forceFill([
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
            && config('services.trading_engine.paper_entry_integration_enabled', false) === true;
    }
}
