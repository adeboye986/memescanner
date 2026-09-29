<?php

namespace App\Services\TradingEngine;

use App\Models\TradingEngineEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TradingEngineProjectionStatus
{
    private const STATUSES = [
        TradingEngineEvent::STATUS_STORED,
        TradingEngineEvent::STATUS_PROJECTED,
        TradingEngineEvent::STATUS_RETRYABLE,
        TradingEngineEvent::STATUS_DEFERRED,
        TradingEngineEvent::STATUS_FAILED,
        TradingEngineEvent::STATUS_UNHANDLED,
    ];

    public function __construct(
        private TradingEngineProjectionEligibility $eligibility,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $now = now();
        $counts = array_fill_keys(self::STATUSES, 0);

        $this->eligibility->projectableQuery()
            ->selectRaw('handling_status, COUNT(*) AS aggregate_count')
            ->groupBy('handling_status')
            ->get()
            ->each(function (TradingEngineEvent $event) use (&$counts): void {
                if (array_key_exists($event->handling_status, $counts)) {
                    $counts[$event->handling_status] = (int) $event->getAttribute('aggregate_count');
                }
            });

        $staleStored = $this->eligibility->projectableQuery();
        $this->eligibility->applyStaleStoredEligibility($staleStored, $now);

        $dueRetryable = $this->eligibility->projectableQuery();
        $this->eligibility->applyDueRetryableEligibility($dueRetryable, $now);

        $dueDeferred = $this->eligibility->projectableQuery();
        $this->eligibility->applyDueDeferredEligibility($dueDeferred, $now);

        $activeLeases = $this->eligibility->projectableQuery();
        $this->eligibility->applyActiveLease($activeLeases, $now);

        return [
            'observed_at' => $now->toIso8601String(),
            'configuration' => [
                'engine_enabled' => config('services.trading_engine.enabled', false) === true,
                'projection_enabled' => config('services.trading_engine.opportunity_projection_enabled', false) === true,
                'automatic_recovery_enabled' => $this->eligibility->enabled(),
                'batch_size' => $this->eligibility->batchSize(),
                'stale_after_seconds' => $this->eligibility->staleAfterSeconds(),
                'lease_seconds' => $this->eligibility->leaseSeconds(),
            ],
            'inbox' => [
                'total_projectable' => array_sum($counts),
                'counts' => $counts,
            ],
            'recovery' => [
                'stale_stored_eligible' => $staleStored->count(),
                'retryable_due' => $dueRetryable->count(),
                'deferred_causally_eligible' => $dueDeferred->count(),
                'active_leases' => $activeLeases->count(),
            ],
            'oldest' => [
                'outstanding' => $this->oldest(
                    $this->eligibility->projectableQuery()
                        ->whereIn('handling_status', TradingEngineProjectionEligibility::OUTSTANDING_STATUSES),
                    $now,
                ),
                'retryable' => $this->oldestForStatus(TradingEngineEvent::STATUS_RETRYABLE, $now),
                'deferred' => $this->oldestForStatus(TradingEngineEvent::STATUS_DEFERRED, $now),
                'failed' => $this->oldestForStatus(TradingEngineEvent::STATUS_FAILED, $now),
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public function inspect(string $eventId): ?array
    {
        $event = TradingEngineEvent::query()
            ->select([
                'id',
                'event_id',
                'event_type',
                'aggregate_type',
                'aggregate_id',
                'aggregate_version',
                'correlation_id',
                'causation_id',
                'handling_status',
                'handling_attempts',
                'handling_error_code',
                'next_handling_at',
                'received_at',
                'created_at',
                'handled_at',
            ])
            ->where('event_id', $eventId)
            ->first();

        if (! $event) {
            return null;
        }

        $now = now();

        return [
            'event_id' => $event->event_id,
            'event_type' => $event->event_type,
            'aggregate_type' => $event->aggregate_type,
            'aggregate_id' => $event->aggregate_id,
            'aggregate_version' => $event->aggregate_version,
            'handling_status' => $event->handling_status,
            'handling_attempts' => $event->handling_attempts,
            'handling_error_code' => $event->handling_error_code,
            'next_handling_at' => $event->next_handling_at?->toIso8601String(),
            'received_at' => $event->received_at?->toIso8601String(),
            'created_at' => $event->created_at?->toIso8601String(),
            'handled_at' => $event->handled_at?->toIso8601String(),
            'correlation_id' => $event->correlation_id,
            'causation_id' => $event->causation_id,
            'causal_dependency_satisfied' => $this->eligibility->causalDependencySatisfied($event),
            'automatic_recovery_eligible' => $this->eligibility->automaticRecoveryEligible($event, $now),
            'manual_retry_eligible' => $this->eligibility->manualRetryBlockCode($event, $now) === null,
            'active_lease' => $event->next_handling_at?->isAfter($now) === true
                && in_array($event->handling_status, TradingEngineProjectionEligibility::OUTSTANDING_STATUSES, true),
            'projection_exists' => $this->projectionExists($event),
        ];
    }

    /**
     * @param  Builder<TradingEngineEvent>  $query
     * @return array<string, mixed>|null
     */
    private function oldest(Builder $query, Carbon $now): ?array
    {
        $event = $query
            ->select(['event_id', 'event_type', 'handling_status', 'received_at'])
            ->orderBy('received_at')
            ->orderBy('id')
            ->first();

        if (! $event) {
            return null;
        }

        return [
            'event_id' => $event->event_id,
            'event_type' => $event->event_type,
            'handling_status' => $event->handling_status,
            'received_at' => $event->received_at->toIso8601String(),
            'age_seconds' => max(0, (int) $event->received_at->diffInSeconds($now)),
        ];
    }

    /** @return array<string, mixed>|null */
    private function oldestForStatus(string $status, Carbon $now): ?array
    {
        return $this->oldest(
            $this->eligibility->projectableQuery()->where('handling_status', $status),
            $now,
        );
    }

    private function projectionExists(TradingEngineEvent $event): bool
    {
        return match ($event->event_type) {
            'opportunity.recorded.v1' => DB::table('trading_engine_opportunity_links')
                ->where('recorded_event_id', $event->event_id)
                ->exists(),
            'opportunity.evaluated.v1' => DB::table('trading_engine_opportunity_evaluations')
                ->where('evaluation_event_id', $event->event_id)
                ->exists(),
            default => false,
        };
    }
}
