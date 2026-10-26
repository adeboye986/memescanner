<?php

namespace App\Services\TradingEngine;

use App\Jobs\SubmitTradingEnginePaperEntry;
use App\Jobs\SubmitTradingEnginePaperFinancialObservation;
use App\Models\TradingEnginePaperEntryIntent;
use App\Models\TradingEnginePaperFinancialObservation;
use Closure;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

class TradingEnginePaperDeliveryTracker
{
    public const MAX_DELIVERY_ATTEMPTS = 5;

    public const MAX_RECOVERY_DISPATCHES = 5;

    private const STALE_ATTEMPT_AFTER_SECONDS = 120;

    private const RETRY_RECOVERY_AFTER_SECONDS = 600;

    public function __construct(private Dispatcher $bus) {}

    /** @param Closure(): ?TradingEnginePaperEntryIntent $persist */
    public function queueEntry(Closure $persist): ?TradingEnginePaperEntryIntent
    {
        $this->assertDatabaseQueueIsAtomic();

        return DB::connection(DB::getDefaultConnection())->transaction(function () use ($persist): ?TradingEnginePaperEntryIntent {
            $intent = $persist();

            if (! $intent instanceof TradingEnginePaperEntryIntent) {
                return null;
            }

            if ($intent->status === TradingEnginePaperEntryIntent::STATUS_PENDING) {
                $intent->forceFill([
                    'status' => TradingEnginePaperEntryIntent::STATUS_QUEUED,
                    'queued_at' => now(),
                ])->save();
                $this->dispatchEntry($intent);
            }

            return $intent;
        }, 3);
    }

    /** @param Closure(): TradingEnginePaperFinancialObservation $persist */
    public function queueObservation(Closure $persist): TradingEnginePaperFinancialObservation
    {
        $this->assertDatabaseQueueIsAtomic();

        return DB::connection(DB::getDefaultConnection())->transaction(function () use ($persist): TradingEnginePaperFinancialObservation {
            $observation = $persist();

            if ($observation->status === TradingEnginePaperFinancialObservation::STATUS_PENDING) {
                $observation->forceFill([
                    'status' => TradingEnginePaperFinancialObservation::STATUS_QUEUED,
                    'queued_at' => now(),
                ])->save();
                $this->dispatchObservation($observation);
            }

            return $observation;
        }, 3);
    }

    public function beginEntryAttempt(TradingEnginePaperEntryIntent $intent): ?int
    {
        return $this->beginAttempt($intent);
    }

    public function beginObservationAttempt(TradingEnginePaperFinancialObservation $observation): ?int
    {
        return $this->beginAttempt($observation);
    }

    /** @param array{operationId: string, walletId: string, intentId: string, orderId: string, fillId: string, positionId: string, eventId: string, status: string, duplicate: bool} $response */
    public function entryAcknowledged(
        TradingEnginePaperEntryIntent $intent,
        array $response,
        int $attempt,
    ): void {
        $identifiers = [
            'engine_operation_id' => $response['operationId'],
            'engine_wallet_id' => $response['walletId'],
            'engine_intent_id' => $response['intentId'],
            'engine_order_id' => $response['orderId'],
            'engine_fill_id' => $response['fillId'],
            'engine_position_id' => $response['positionId'],
            'engine_event_id' => $response['eventId'],
        ];

        $this->attemptTransition($intent, $attempt, function (Model $locked) use ($identifiers): void {
            foreach ($identifiers as $column => $value) {
                if ($locked->{$column} !== null && $locked->{$column} !== $value) {
                    throw new LogicException('The PAPER entry response conflicts with persisted engine identity.');
                }
            }

            $locked->forceFill([
                ...$identifiers,
                'status' => TradingEnginePaperEntryIntent::STATUS_SUBMITTED,
                'last_error_code' => null,
                'acceptance_unknown_at' => null,
                'submitted_at' => now(),
            ])->save();
        });
    }

    /** @param array{operationId: string, positionId: string, decisionId: string, eventId: string, settlementId: string|null, decision: string, duplicate: bool} $response */
    public function observationAcknowledged(
        TradingEnginePaperFinancialObservation $observation,
        array $response,
        int $attempt,
    ): void {
        $identifiers = [
            'engine_operation_id' => $response['operationId'],
            'engine_decision_id' => $response['decisionId'],
            'engine_event_id' => $response['eventId'],
            'engine_settlement_id' => $response['settlementId'],
            'engine_decision' => $response['decision'],
        ];

        $this->attemptTransition($observation, $attempt, function (Model $locked) use ($identifiers): void {
            foreach ($identifiers as $column => $value) {
                if ($locked->{$column} !== null && $locked->{$column} !== $value) {
                    throw new LogicException('The PAPER financial observation response conflicts with persisted engine identity.');
                }
            }

            $locked->forceFill([
                ...$identifiers,
                'status' => TradingEnginePaperFinancialObservation::STATUS_SUBMITTED,
                'last_error_code' => null,
                'acceptance_unknown_at' => null,
                'submitted_at' => now(),
            ])->save();
        });
    }

    public function entryRetrying(TradingEnginePaperEntryIntent $intent, string $errorCode, int $attempt): void
    {
        $this->retrying($intent, $errorCode, $attempt);
    }

    public function observationRetrying(
        TradingEnginePaperFinancialObservation $observation,
        string $errorCode,
        int $attempt,
    ): void {
        $this->retrying($observation, $errorCode, $attempt);
    }

    public function entryAcceptanceUnknown(
        TradingEnginePaperEntryIntent $intent,
        string $errorCode,
        ?int $attempt,
    ): void {
        $this->acceptanceUnknown($intent, $errorCode, $attempt);
    }

    public function observationAcceptanceUnknown(
        TradingEnginePaperFinancialObservation $observation,
        string $errorCode,
        ?int $attempt,
    ): void {
        $this->acceptanceUnknown($observation, $errorCode, $attempt);
    }

    public function entryRejected(TradingEnginePaperEntryIntent $intent, string $errorCode, int $attempt): void
    {
        $this->rejected($intent, $errorCode, $attempt);
    }

    public function observationRejected(
        TradingEnginePaperFinancialObservation $observation,
        string $errorCode,
        int $attempt,
    ): void {
        $this->rejected($observation, $errorCode, $attempt);
    }

    public function recoverEntries(int $limit = 100): int
    {
        return $this->recover(
            TradingEnginePaperEntryIntent::class,
            fn (Model $model): mixed => $this->dispatchEntry($model),
            $limit,
        );
    }

    public function recoverObservations(int $limit = 100): int
    {
        return $this->recover(
            TradingEnginePaperFinancialObservation::class,
            fn (Model $model): mixed => $this->dispatchObservation($model),
            $limit,
        );
    }

    private function beginAttempt(Model $record): ?int
    {
        return DB::transaction(function () use ($record): ?int {
            $locked = $record::query()->lockForUpdate()->findOrFail($record->getKey());

            if (in_array($locked->status, ['submitted', 'projected', 'failed'], true)
                || $locked->attempt_count >= self::MAX_DELIVERY_ATTEMPTS) {
                return null;
            }

            if ($locked->status === 'submitting') {
                if ($locked->submitting_at?->isAfter(now()->subSeconds(self::STALE_ATTEMPT_AFTER_SECONDS))) {
                    return null;
                }
            } elseif (! in_array($locked->status, ['pending', 'queued', 'retrying', 'acceptance_unknown'], true)) {
                throw new LogicException('The PAPER delivery cannot begin an attempt from its current state.');
            }

            $attempt = $locked->attempt_count + 1;
            $locked->forceFill([
                'status' => 'submitting',
                'attempt_count' => $attempt,
                'last_error_code' => null,
                'submitting_at' => now(),
                'last_attempt_at' => now(),
            ])->save();

            return $attempt;
        }, 3);
    }

    private function retrying(Model $record, string $errorCode, int $attempt): void
    {
        $this->attemptTransition($record, $attempt, function (Model $locked) use ($errorCode): void {
            $locked->forceFill([
                'status' => 'retrying',
                'last_error_code' => $this->safeCode($errorCode),
            ])->save();
        });
    }

    private function rejected(Model $record, string $errorCode, int $attempt): void
    {
        $this->attemptTransition($record, $attempt, function (Model $locked) use ($errorCode): void {
            $locked->forceFill([
                'status' => 'failed',
                'last_error_code' => $this->safeCode($errorCode),
                'definitively_rejected_at' => now(),
            ])->save();
        });
    }

    private function acceptanceUnknown(Model $record, string $errorCode, ?int $attempt): void
    {
        DB::transaction(function () use ($record, $errorCode, $attempt): void {
            $locked = $record::query()->lockForUpdate()->findOrFail($record->getKey());

            if (in_array($locked->status, ['submitted', 'projected', 'failed'], true)) {
                return;
            }

            if ($attempt !== null
                && ($locked->attempt_count !== $attempt
                    || ! in_array($locked->status, ['submitting', 'retrying'], true))) {
                return;
            }

            if ($attempt === null && ! in_array($locked->status, ['pending', 'queued', 'retrying'], true)) {
                return;
            }

            $locked->forceFill([
                'status' => 'acceptance_unknown',
                'last_error_code' => $this->safeCode($errorCode),
                'acceptance_unknown_at' => now(),
            ])->save();
        }, 3);
    }

    /** @param Closure(Model): void $transition */
    private function attemptTransition(Model $record, int $attempt, Closure $transition): void
    {
        DB::transaction(function () use ($record, $attempt, $transition): void {
            $locked = $record::query()->lockForUpdate()->findOrFail($record->getKey());

            if ($locked->status !== 'submitting' || $locked->attempt_count !== $attempt) {
                return;
            }

            $transition($locked);
        }, 3);
    }

    /**
     * @param  class-string<TradingEnginePaperEntryIntent|TradingEnginePaperFinancialObservation>  $model
     * @param  Closure(Model): mixed  $dispatch
     */
    private function recover(string $model, Closure $dispatch, int $limit): int
    {
        $this->assertDatabaseQueueIsAtomic();
        $boundedLimit = max(1, min($limit, 500));

        return DB::connection(DB::getDefaultConnection())->transaction(function () use ($model, $dispatch, $boundedLimit): int {
            $staleAttempt = now()->subSeconds(self::STALE_ATTEMPT_AFTER_SECONDS);
            $staleRetry = now()->subSeconds(self::RETRY_RECOVERY_AFTER_SECONDS);
            $records = $model::query()
                ->where('attempt_count', '<', self::MAX_DELIVERY_ATTEMPTS)
                ->where('recovery_count', '<', self::MAX_RECOVERY_DISPATCHES)
                ->where(function (Builder $query) use ($staleAttempt, $staleRetry): void {
                    $query->where(function (Builder $candidate) use ($staleAttempt): void {
                        $candidate->whereIn('status', ['pending', 'queued', 'acceptance_unknown'])
                            ->where('updated_at', '<=', $staleAttempt);
                    })->orWhere(function (Builder $candidate) use ($staleAttempt): void {
                        $candidate->where('status', 'submitting')
                            ->where('submitting_at', '<=', $staleAttempt);
                    })->orWhere(function (Builder $candidate) use ($staleRetry): void {
                        $candidate->where('status', 'retrying')
                            ->where('updated_at', '<=', $staleRetry);
                    });
                })
                ->where(function (Builder $query) use ($staleAttempt): void {
                    $query->whereNull('last_recovered_at')
                        ->orWhere('last_recovered_at', '<=', $staleAttempt);
                })
                ->orderBy('id')
                ->limit($boundedLimit)
                ->lockForUpdate()
                ->get();

            foreach ($records as $record) {
                $record->forceFill([
                    'recovery_count' => $record->recovery_count + 1,
                    'last_recovered_at' => now(),
                ])->save();
                $dispatch($record);
            }

            return $records->count();
        }, 3);
    }

    private function dispatchEntry(Model $model): mixed
    {
        if (! $model instanceof TradingEnginePaperEntryIntent) {
            throw new LogicException('A PAPER entry dispatch requires an entry intent.');
        }

        return $this->bus->dispatch(
            (new SubmitTradingEnginePaperEntry($model->getKey()))->onConnection('database'),
        );
    }

    private function dispatchObservation(Model $model): mixed
    {
        if (! $model instanceof TradingEnginePaperFinancialObservation) {
            throw new LogicException('A PAPER observation dispatch requires an observation.');
        }

        return $this->bus->dispatch(
            (new SubmitTradingEnginePaperFinancialObservation($model->getKey()))->onConnection('database'),
        );
    }

    private function assertDatabaseQueueIsAtomic(): void
    {
        if (config('queue.connections.database.driver') !== 'database') {
            throw new LogicException('PAPER delivery requires the database queue driver.');
        }

        $databaseConnection = DB::getDefaultConnection();
        $queueDatabaseConnection = config('queue.connections.database.connection');

        if (is_string($queueDatabaseConnection)
            && $queueDatabaseConnection !== ''
            && $queueDatabaseConnection !== $databaseConnection) {
            throw new LogicException('PAPER delivery state and queue jobs must use the same database connection.');
        }
    }

    private function safeCode(string $errorCode): string
    {
        return preg_match('/^[A-Z][A-Z0-9_]{0,127}$/D', $errorCode) === 1
            ? $errorCode
            : 'UNEXPECTED_FAILURE';
    }
}
