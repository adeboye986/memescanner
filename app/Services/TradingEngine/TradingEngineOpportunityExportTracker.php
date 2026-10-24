<?php

namespace App\Services\TradingEngine;

use App\Models\TradeOpportunity;
use App\Models\TradingEngineOpportunityExport;
use App\Services\ScanExecutionRecorder;
use Illuminate\Support\Facades\DB;
use LogicException;

class TradingEngineOpportunityExportTracker
{
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

    public function beginAttempt(TradingEngineOpportunityExport $export): bool
    {
        $started = DB::transaction(function () use ($export): bool {
            $locked = TradingEngineOpportunityExport::query()->lockForUpdate()->findOrFail($export->getKey());

            if (in_array($locked->status, [
                TradingEngineOpportunityExport::STATUS_ACKNOWLEDGED,
                TradingEngineOpportunityExport::STATUS_SKIPPED,
                TradingEngineOpportunityExport::STATUS_PERMANENTLY_FAILED,
            ], true)) {
                return false;
            }

            if (! in_array($locked->status, [
                TradingEngineOpportunityExport::STATUS_QUEUED,
                TradingEngineOpportunityExport::STATUS_RETRYING,
            ], true)) {
                throw new LogicException('The opportunity export cannot begin an attempt from its current state.');
            }

            $locked->forceFill([
                'status' => TradingEngineOpportunityExport::STATUS_SUBMITTING,
                'attempt_count' => $locked->attempt_count + 1,
                'last_error_code' => null,
                'submitting_at' => now(),
                'last_attempt_at' => now(),
            ])->save();

            return true;
        });

        if ($started) {
            $this->scanExecutions->refreshForOpportunity($export->trade_opportunity_id);
        }

        return $started;
    }

    /** @param array{operationId: string, opportunityId: string, eventId: string, status: string, duplicate: bool} $response */
    public function acknowledged(TradingEngineOpportunityExport $export, array $response): void
    {
        $this->transition($export, [
            'status' => TradingEngineOpportunityExport::STATUS_ACKNOWLEDGED,
            'last_error_code' => null,
            'engine_operation_id' => $response['operationId'],
            'engine_opportunity_id' => $response['opportunityId'],
            'engine_event_id' => $response['eventId'],
            'duplicate' => $response['duplicate'],
            'acknowledged_at' => now(),
        ], [TradingEngineOpportunityExport::STATUS_SUBMITTING]);
    }

    public function retrying(TradingEngineOpportunityExport $export, string $errorCode): void
    {
        $this->transition($export, [
            'status' => TradingEngineOpportunityExport::STATUS_RETRYING,
            'last_error_code' => $this->safeCode($errorCode),
        ], [TradingEngineOpportunityExport::STATUS_SUBMITTING]);
    }

    public function permanentlyFailed(TradingEngineOpportunityExport $export, string $errorCode): void
    {
        $this->transition($export, [
            'status' => TradingEngineOpportunityExport::STATUS_PERMANENTLY_FAILED,
            'last_error_code' => $this->safeCode($errorCode),
            'permanently_failed_at' => now(),
        ], [
            TradingEngineOpportunityExport::STATUS_QUEUED,
            TradingEngineOpportunityExport::STATUS_SUBMITTING,
            TradingEngineOpportunityExport::STATUS_RETRYING,
        ]);
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
        ]);
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
        });

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
