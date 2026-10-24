<?php

namespace App\Services\TradingEngine;

use App\Models\TradeOpportunity;
use App\Models\TradingEngineOpportunityExport;
use App\Services\ScanExecutionRecorder;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;

class TradingEngineOpportunityExportTracker
{
    private const STALE_ATTEMPT_AFTER_SECONDS = 120;

    public function __construct(private ScanExecutionRecorder $scanExecutions) {}

    public function queued(TradeOpportunity $opportunity, string $idempotencyKey): TradingEngineOpportunityExport
    {
        $export = TradingEngineOpportunityExport::query()->firstOrCreate(
            ['trade_opportunity_id' => $opportunity->getKey()],
            [
                'idempotency_key' => $idempotencyKey,
                'status' => TradingEngineOpportunityExport::STATUS_QUEUED,
                'queued_at' => now(),
            ],
        );

        $this->assertIdentity($export, $opportunity, $idempotencyKey);
        $this->scanExecutions->refreshForOpportunity($opportunity->getKey());

        return $export;
    }

    public function queueAndDispatch(
        TradeOpportunity $opportunity,
        string $idempotencyKey,
        Closure $dispatch,
    ): TradingEngineOpportunityExport {
        $databaseConnection = DB::getDefaultConnection();
        $queueDatabaseConnection = config('queue.connections.database.connection');

        if (is_string($queueDatabaseConnection)
            && $queueDatabaseConnection !== ''
            && $queueDatabaseConnection !== $databaseConnection) {
            throw new LogicException('Opportunity export tracking and the database queue must use the same database connection.');
        }

        $wasCreated = false;
        $export = DB::connection($databaseConnection)->transaction(function () use (
            $opportunity,
            $idempotencyKey,
            $dispatch,
            &$wasCreated,
        ): TradingEngineOpportunityExport {
            $export = TradingEngineOpportunityExport::query()->firstOrCreate(
                ['trade_opportunity_id' => $opportunity->getKey()],
                [
                    'idempotency_key' => $idempotencyKey,
                    'status' => TradingEngineOpportunityExport::STATUS_QUEUED,
                    'queued_at' => now(),
                ],
            );

            $this->assertIdentity($export, $opportunity, $idempotencyKey);
            $wasCreated = $export->wasRecentlyCreated;

            if ($wasCreated) {
                $dispatch();
            }

            return $export;
        }, 3);

        if ($wasCreated) {
            $this->scanExecutions->refreshForOpportunity($opportunity->getKey());
        }

        return $export;
    }

    public function skipped(TradeOpportunity $opportunity, string $idempotencyKey, string $errorCode): TradingEngineOpportunityExport
    {
        $export = TradingEngineOpportunityExport::query()->firstOrCreate(
            ['trade_opportunity_id' => $opportunity->getKey()],
            [
                'idempotency_key' => $idempotencyKey,
                'status' => TradingEngineOpportunityExport::STATUS_SKIPPED,
                'last_error_code' => $this->safeCode($errorCode),
                'skipped_at' => now(),
            ],
        );

        $this->assertIdentity($export, $opportunity, $idempotencyKey);
        $this->scanExecutions->refreshForOpportunity($opportunity->getKey());

        return $export;
    }

    /** @param array<string, mixed> $payload */
    public function forJob(array $payload, string $idempotencyKey): TradingEngineOpportunityExport
    {
        $existing = TradingEngineOpportunityExport::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return $existing;
        }

        $sourceId = data_get($payload, 'source.opportunity_id');

        if (! is_string($sourceId) || preg_match('/^[1-9][0-9]*$/D', $sourceId) !== 1) {
            throw new LogicException('The queued opportunity payload has no valid Laravel opportunity identifier.');
        }

        $opportunity = TradeOpportunity::query()->findOrFail((int) $sourceId);
        $expectedKey = 'opportunity:record:laravel:'.$opportunity->getKey().':v1';

        if (! hash_equals($expectedKey, $idempotencyKey)) {
            throw new LogicException('The queued opportunity idempotency key does not match its Laravel opportunity.');
        }

        return $this->queued($opportunity, $idempotencyKey);
    }

    public function beginAttempt(TradingEngineOpportunityExport $export): ?int
    {
        $attempt = DB::transaction(function () use ($export): ?int {
            $locked = TradingEngineOpportunityExport::query()->lockForUpdate()->findOrFail($export->getKey());

            if (in_array($locked->status, [
                TradingEngineOpportunityExport::STATUS_ACKNOWLEDGED,
                TradingEngineOpportunityExport::STATUS_SKIPPED,
                TradingEngineOpportunityExport::STATUS_PERMANENTLY_FAILED,
            ], true)) {
                return null;
            }

            if ($locked->status === TradingEngineOpportunityExport::STATUS_SUBMITTING) {
                if ($locked->submitting_at?->isAfter(now()->subSeconds(self::STALE_ATTEMPT_AFTER_SECONDS))) {
                    return null;
                }
            } elseif (! in_array($locked->status, [
                TradingEngineOpportunityExport::STATUS_QUEUED,
                TradingEngineOpportunityExport::STATUS_RETRYING,
                TradingEngineOpportunityExport::STATUS_ACCEPTANCE_UNKNOWN,
            ], true)) {
                throw new LogicException('The opportunity export cannot begin an attempt from its current state.');
            }

            $attempt = $locked->attempt_count + 1;
            $locked->forceFill([
                'status' => TradingEngineOpportunityExport::STATUS_SUBMITTING,
                'attempt_count' => $attempt,
                'last_error_code' => null,
                'submitting_at' => now(),
                'last_attempt_at' => now(),
            ])->save();

            return $attempt;
        }, 3);

        if ($attempt !== null) {
            $this->scanExecutions->refreshForOpportunity($export->trade_opportunity_id);
        }

        return $attempt;
    }

    /** @param array{operationId: string, opportunityId: string, eventId: string, status: string, duplicate: bool} $response */
    public function acknowledged(TradingEngineOpportunityExport $export, array $response, int $attempt): void
    {
        $this->attemptTransition($export, $attempt, [
            'status' => TradingEngineOpportunityExport::STATUS_ACKNOWLEDGED,
            'last_error_code' => null,
            'engine_operation_id' => $response['operationId'],
            'engine_opportunity_id' => $response['opportunityId'],
            'engine_event_id' => $response['eventId'],
            'duplicate' => $response['duplicate'],
            'acknowledged_at' => now(),
        ]);
    }

    public function retrying(TradingEngineOpportunityExport $export, string $errorCode, int $attempt): void
    {
        $this->attemptTransition($export, $attempt, [
            'status' => TradingEngineOpportunityExport::STATUS_RETRYING,
            'last_error_code' => $this->safeCode($errorCode),
        ]);
    }

    public function rejected(TradingEngineOpportunityExport $export, string $errorCode, int $attempt): void
    {
        $this->attemptTransition($export, $attempt, [
            'status' => TradingEngineOpportunityExport::STATUS_PERMANENTLY_FAILED,
            'last_error_code' => $this->safeCode($errorCode),
            'permanently_failed_at' => now(),
        ]);
    }

    public function dispatchFailed(TradeOpportunity $opportunity, string $idempotencyKey): void
    {
        $export = TradingEngineOpportunityExport::query()->firstOrCreate(
            ['trade_opportunity_id' => $opportunity->getKey()],
            [
                'idempotency_key' => $idempotencyKey,
                'status' => TradingEngineOpportunityExport::STATUS_PERMANENTLY_FAILED,
                'last_error_code' => 'QUEUE_DISPATCH_FAILED',
                'permanently_failed_at' => now(),
            ],
        );

        $this->assertIdentity($export, $opportunity, $idempotencyKey);
        $this->scanExecutions->refreshForOpportunity($opportunity->getKey());
    }

    public function acceptanceUnknown(
        TradingEngineOpportunityExport $export,
        string $errorCode,
        ?int $attempt,
    ): void {
        $changed = DB::transaction(function () use ($export, $errorCode, $attempt): bool {
            $locked = TradingEngineOpportunityExport::query()->lockForUpdate()->findOrFail($export->getKey());

            if (in_array($locked->status, [
                TradingEngineOpportunityExport::STATUS_ACKNOWLEDGED,
                TradingEngineOpportunityExport::STATUS_SKIPPED,
                TradingEngineOpportunityExport::STATUS_PERMANENTLY_FAILED,
            ], true)) {
                return false;
            }

            if ($attempt !== null) {
                if ($locked->attempt_count !== $attempt
                    || ! in_array($locked->status, [
                        TradingEngineOpportunityExport::STATUS_SUBMITTING,
                        TradingEngineOpportunityExport::STATUS_RETRYING,
                    ], true)) {
                    return false;
                }
            } elseif (! in_array($locked->status, [
                TradingEngineOpportunityExport::STATUS_QUEUED,
                TradingEngineOpportunityExport::STATUS_RETRYING,
            ], true)) {
                return false;
            }

            $locked->forceFill([
                'status' => TradingEngineOpportunityExport::STATUS_ACCEPTANCE_UNKNOWN,
                'last_error_code' => $this->safeCode($errorCode),
            ])->save();

            return true;
        }, 3);

        if ($changed) {
            $this->scanExecutions->refreshForOpportunity($export->trade_opportunity_id);
        }
    }

    public function acknowledgedByRecordedEvent(
        TradeOpportunity $opportunity,
        string $idempotencyKey,
        string $operationId,
        string $engineOpportunityId,
        string $eventId,
    ): void {
        $changed = DB::transaction(function () use (
            $opportunity,
            $idempotencyKey,
            $operationId,
            $engineOpportunityId,
            $eventId,
        ): bool {
            $export = TradingEngineOpportunityExport::query()
                ->where('trade_opportunity_id', $opportunity->getKey())
                ->lockForUpdate()
                ->first();

            if (! $export) {
                TradingEngineOpportunityExport::query()->create([
                    'trade_opportunity_id' => $opportunity->getKey(),
                    'idempotency_key' => $idempotencyKey,
                    'status' => TradingEngineOpportunityExport::STATUS_ACKNOWLEDGED,
                    'engine_operation_id' => $operationId,
                    'engine_opportunity_id' => $engineOpportunityId,
                    'engine_event_id' => $eventId,
                    'acknowledged_at' => now(),
                ]);

                return true;
            }

            $this->assertIdentity($export, $opportunity, $idempotencyKey);

            if ($export->status === TradingEngineOpportunityExport::STATUS_ACKNOWLEDGED) {
                if (($export->engine_operation_id !== null && $export->engine_operation_id !== $operationId)
                    || ($export->engine_opportunity_id !== null && $export->engine_opportunity_id !== $engineOpportunityId)
                    || ($export->engine_event_id !== null && $export->engine_event_id !== $eventId)) {
                    throw new LogicException('The authenticated opportunity acknowledgement conflicts with durable export evidence.');
                }

                return false;
            }

            $export->forceFill([
                'status' => TradingEngineOpportunityExport::STATUS_ACKNOWLEDGED,
                'last_error_code' => null,
                'engine_operation_id' => $operationId,
                'engine_opportunity_id' => $engineOpportunityId,
                'engine_event_id' => $eventId,
                'acknowledged_at' => now(),
                'permanently_failed_at' => null,
            ])->save();

            return true;
        }, 3);

        if ($changed) {
            $this->scanExecutions->refreshForOpportunity($opportunity->getKey());
        }
    }

    public function skippedJob(TradingEngineOpportunityExport $export, string $errorCode): void
    {
        $this->transition($export, [
            'status' => TradingEngineOpportunityExport::STATUS_SKIPPED,
            'last_error_code' => $this->safeCode($errorCode),
            'skipped_at' => now(),
        ], [
            TradingEngineOpportunityExport::STATUS_QUEUED,
            TradingEngineOpportunityExport::STATUS_RETRYING,
            TradingEngineOpportunityExport::STATUS_ACCEPTANCE_UNKNOWN,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function attemptTransition(
        TradingEngineOpportunityExport $export,
        int $attempt,
        array $attributes,
    ): void {
        $changed = DB::transaction(function () use ($export, $attempt, $attributes): bool {
            $locked = TradingEngineOpportunityExport::query()->lockForUpdate()->findOrFail($export->getKey());

            if ($locked->status === TradingEngineOpportunityExport::STATUS_ACKNOWLEDGED
                || $locked->status === TradingEngineOpportunityExport::STATUS_SKIPPED
                || $locked->status === TradingEngineOpportunityExport::STATUS_PERMANENTLY_FAILED
                || $locked->status !== TradingEngineOpportunityExport::STATUS_SUBMITTING
                || $locked->attempt_count !== $attempt) {
                return false;
            }

            $locked->forceFill($attributes)->save();

            return true;
        }, 3);

        if ($changed) {
            $this->scanExecutions->refreshForOpportunity($export->trade_opportunity_id);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $allowedFrom
     */
    private function transition(
        TradingEngineOpportunityExport $export,
        array $attributes,
        array $allowedFrom,
    ): void {
        $changed = DB::transaction(function () use ($export, $attributes, $allowedFrom): bool {
            $locked = TradingEngineOpportunityExport::query()->lockForUpdate()->findOrFail($export->getKey());
            $targetStatus = (string) $attributes['status'];

            if ($locked->status === $targetStatus) {
                return false;
            }

            if (in_array($locked->status, [
                TradingEngineOpportunityExport::STATUS_ACKNOWLEDGED,
                TradingEngineOpportunityExport::STATUS_SKIPPED,
                TradingEngineOpportunityExport::STATUS_PERMANENTLY_FAILED,
            ], true)) {
                return false;
            }

            if (! in_array($locked->status, $allowedFrom, true)) {
                throw new LogicException('The opportunity export transition is invalid.');
            }

            $locked->forceFill($attributes)->save();

            return true;
        }, 3);

        if ($changed) {
            $this->scanExecutions->refreshForOpportunity($export->trade_opportunity_id);
        }
    }

    private function assertIdentity(
        TradingEngineOpportunityExport $export,
        TradeOpportunity $opportunity,
        string $idempotencyKey,
    ): void {
        if ($export->trade_opportunity_id !== $opportunity->getKey()
            || ! hash_equals($export->idempotency_key, $idempotencyKey)) {
            throw new LogicException('The durable opportunity export identity conflicts with the requested export.');
        }
    }

    private function safeCode(string $errorCode): string
    {
        return preg_match('/^[A-Z][A-Z0-9_]{0,127}$/D', $errorCode) === 1
            ? $errorCode
            : 'UNEXPECTED_FAILURE';
    }
}
