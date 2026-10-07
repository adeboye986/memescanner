<?php

namespace App\Services\TradingEngine;

use App\Models\TradingEngineEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TradingEngineProjectionEligibility
{
    public const MAXIMUM_BATCH_SIZE = 500;

    public const MAXIMUM_INTERVAL_SECONDS = 3600;

    public const MINIMUM_INTERVAL_SECONDS = 30;

    public const PROJECTABLE_EVENT_TYPES = [
        'opportunity.recorded.v1',
        'opportunity.evaluated.v1',
        'paper.position.recorded.v1',
        'paper.position.evaluated.v1',
        'paper.exit.requested.v1',
        'paper.entry.executed.v1',
    ];

    public const OUTSTANDING_STATUSES = [
        TradingEngineEvent::STATUS_STORED,
        TradingEngineEvent::STATUS_RETRYABLE,
        TradingEngineEvent::STATUS_DEFERRED,
    ];

    public function enabled(): bool
    {
        return config('services.trading_engine.enabled', false) === true
            && (config('services.trading_engine.opportunity_projection_enabled', false) === true
                || config('services.trading_engine.paper_entry_integration_enabled', false) === true
                || (config('services.trading_engine.paper_lifecycle_integration_enabled', false) === true
                    && config('services.trading_engine.paper_lifecycle_authoritative_enabled', false) === true));
    }

    public function batchSize(): int
    {
        return max(1, min(
            self::MAXIMUM_BATCH_SIZE,
            (int) config('services.trading_engine.projection_recovery_batch_size', 100),
        ));
    }

    public function staleStoredBefore(Carbon $now): Carbon
    {
        return $now->copy()->subSeconds($this->interval(
            'projection_recovery_stale_after_seconds',
            300,
        ));
    }

    public function leaseUntil(Carbon $now): Carbon
    {
        return $now->copy()->addSeconds($this->interval(
            'projection_recovery_lease_seconds',
            120,
        ));
    }

    public function staleAfterSeconds(): int
    {
        return $this->interval('projection_recovery_stale_after_seconds', 300);
    }

    public function leaseSeconds(): int
    {
        return $this->interval('projection_recovery_lease_seconds', 120);
    }

    /** @return Builder<TradingEngineEvent> */
    public function projectableQuery(): Builder
    {
        return TradingEngineEvent::query()
            ->whereIn('event_type', self::PROJECTABLE_EVENT_TYPES);
    }

    /** @param Builder<TradingEngineEvent> $query */
    public function applyAutomaticEligibility(Builder $query, Carbon $now): void
    {
        $staleBefore = $this->staleStoredBefore($now);

        $query->where(function (Builder $eligible) use ($now, $staleBefore): void {
            $eligible
                ->where(function (Builder $stored) use ($now, $staleBefore): void {
                    $this->applyStaleStoredEligibility($stored, $now, $staleBefore);
                })
                ->orWhere(function (Builder $retryable) use ($now): void {
                    $this->applyDueRetryableEligibility($retryable, $now);
                })
                ->orWhere(function (Builder $deferred) use ($now): void {
                    $this->applyDueDeferredEligibility($deferred, $now);
                });
        });
    }

    /** @param Builder<TradingEngineEvent> $query */
    public function applyStaleStoredEligibility(
        Builder $query,
        Carbon $now,
        ?Carbon $staleBefore = null,
    ): void {
        $query
            ->where('handling_status', TradingEngineEvent::STATUS_STORED)
            ->where('received_at', '<=', $staleBefore ?? $this->staleStoredBefore($now))
            ->where(function (Builder $lease) use ($now): void {
                $lease
                    ->whereNull('next_handling_at')
                    ->orWhere('next_handling_at', '<=', $now);
            });
    }

    /** @param Builder<TradingEngineEvent> $query */
    public function applyDueRetryableEligibility(Builder $query, Carbon $now): void
    {
        $query
            ->where('handling_status', TradingEngineEvent::STATUS_RETRYABLE)
            ->whereNotNull('next_handling_at')
            ->where('next_handling_at', '<=', $now);
    }

    /** @param Builder<TradingEngineEvent> $query */
    public function applyDueDeferredEligibility(Builder $query, Carbon $now): void
    {
        $query
            ->where('handling_status', TradingEngineEvent::STATUS_DEFERRED)
            ->whereNotNull('next_handling_at')
            ->where('next_handling_at', '<=', $now)
            ->where(function (Builder $causal): void {
                $causal
                    ->where(function (Builder $opportunity): void {
                        $opportunity
                            ->where('event_type', 'opportunity.evaluated.v1')
                            ->whereExists(function ($link): void {
                                $link
                                    ->selectRaw('1')
                                    ->from('trading_engine_opportunity_links')
                                    ->whereColumn(
                                        'trading_engine_opportunity_links.recorded_event_id',
                                        'trading_engine_event_inbox.causation_id',
                                    );
                            });
                    })
                    ->orWhere(function (Builder $paperExit): void {
                        $paperExit
                            ->where('event_type', 'paper.exit.requested.v1')
                            ->whereExists(function ($decision): void {
                                $decision
                                    ->selectRaw('1')
                                    ->from('trading_engine_paper_lifecycle_decisions')
                                    ->whereColumn(
                                        'trading_engine_paper_lifecycle_decisions.event_id',
                                        'trading_engine_event_inbox.causation_id',
                                    );
                            });
                    });
            });
    }

    /** @param Builder<TradingEngineEvent> $query */
    public function applyActiveLease(Builder $query, Carbon $now): void
    {
        $query
            ->whereIn('handling_status', self::OUTSTANDING_STATUSES)
            ->whereNotNull('next_handling_at')
            ->where('next_handling_at', '>', $now);
    }

    public function causalDependencySatisfied(TradingEngineEvent $event): ?bool
    {
        if ($event->event_type === 'opportunity.evaluated.v1') {
            return DB::table('trading_engine_opportunity_links')
                ->where('recorded_event_id', $event->causation_id)
                ->exists();
        }

        if ($event->event_type === 'paper.exit.requested.v1') {
            return DB::table('trading_engine_paper_lifecycle_decisions')
                ->where('event_id', $event->causation_id)
                ->exists();
        }

        return null;
    }

    public function automaticRecoveryEligible(TradingEngineEvent $event, Carbon $now): bool
    {
        $query = $this->projectableQuery()->whereKey($event->getKey());
        $this->applyAutomaticEligibility($query, $now);

        return $query->exists();
    }

    public function manualRetryBlockCode(TradingEngineEvent $event, Carbon $now): ?string
    {
        if (! in_array($event->event_type, self::PROJECTABLE_EVENT_TYPES, true)) {
            return 'PROJECTION_EVENT_TYPE_UNSUPPORTED';
        }

        if ($event->handling_status === TradingEngineEvent::STATUS_PROJECTED) {
            return 'PROJECTION_ALREADY_PROJECTED';
        }

        if ($event->handling_status === TradingEngineEvent::STATUS_FAILED) {
            return 'PROJECTION_TERMINALLY_FAILED';
        }

        if ($event->handling_status === TradingEngineEvent::STATUS_UNHANDLED) {
            return 'PROJECTION_EVENT_UNHANDLED';
        }

        if (! in_array($event->handling_status, self::OUTSTANDING_STATUSES, true)) {
            return 'PROJECTION_STATUS_UNSUPPORTED';
        }

        if (in_array($event->event_type, [
            'opportunity.evaluated.v1',
            'paper.exit.requested.v1',
        ], true)
            && $event->handling_status === TradingEngineEvent::STATUS_DEFERRED
            && $this->causalDependencySatisfied($event) !== true) {
            return 'PROJECTION_CAUSAL_DEPENDENCY_MISSING';
        }

        if ($event->next_handling_at?->isAfter($now)) {
            return 'PROJECTION_LEASE_ACTIVE';
        }

        if (in_array($event->handling_status, [
            TradingEngineEvent::STATUS_RETRYABLE,
            TradingEngineEvent::STATUS_DEFERRED,
        ], true) && $event->next_handling_at === null) {
            return 'PROJECTION_NOT_DUE';
        }

        return null;
    }

    private function interval(string $key, int $default): int
    {
        return max(
            self::MINIMUM_INTERVAL_SECONDS,
            min(
                self::MAXIMUM_INTERVAL_SECONDS,
                (int) config('services.trading_engine.'.$key, $default),
            ),
        );
    }
}
