<?php

namespace App\Services\TradingEngine;

use App\Jobs\ProjectTradingEngineEvent;
use App\Models\TradingEngineEvent;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

class TradingEngineProjectionRecovery
{
    private const MAXIMUM_BATCH_SIZE = 500;

    private const MAXIMUM_INTERVAL_SECONDS = 3600;

    private const MINIMUM_INTERVAL_SECONDS = 30;

    private const PROJECTABLE_EVENT_TYPES = [
        'opportunity.recorded.v1',
        'opportunity.evaluated.v1',
    ];

    public function __construct(
        private Dispatcher $dispatcher,
        private TradingEngineOpportunityProjector $projector,
    ) {}

    /**
     * @return array{enabled: bool, selected: int, dispatched: int, failed: int}
     */
    public function recover(): array
    {
        if (config('services.trading_engine.enabled', false) !== true
            || config('services.trading_engine.opportunity_projection_enabled', false) !== true) {
            return [
                'enabled' => false,
                'selected' => 0,
                'dispatched' => 0,
                'failed' => 0,
            ];
        }

        $eventIds = $this->leaseEligibleEventIds();
        $dispatched = 0;
        $failed = 0;

        foreach ($eventIds as $eventId) {
            try {
                $this->dispatcher->dispatch(new ProjectTradingEngineEvent($eventId));
                $dispatched++;
            } catch (Throwable) {
                $failed++;
                $this->projector->markDispatchFailure($eventId);
            }
        }

        return [
            'enabled' => true,
            'selected' => count($eventIds),
            'dispatched' => $dispatched,
            'failed' => $failed,
        ];
    }

    /** @return array<int, string> */
    private function leaseEligibleEventIds(): array
    {
        $now = now();
        $staleBefore = $now->copy()->subSeconds($this->interval(
            'projection_recovery_stale_after_seconds',
            300,
        ));
        $leaseUntil = $now->copy()->addSeconds($this->interval(
            'projection_recovery_lease_seconds',
            120,
        ));
        $batchSize = max(1, min(
            self::MAXIMUM_BATCH_SIZE,
            (int) config('services.trading_engine.projection_recovery_batch_size', 100),
        ));

        return DB::transaction(function () use ($batchSize, $leaseUntil, $now, $staleBefore): array {
            $events = TradingEngineEvent::query()
                ->select(['id', 'event_id'])
                ->whereIn('event_type', self::PROJECTABLE_EVENT_TYPES)
                ->where(function (Builder $query) use ($now, $staleBefore): void {
                    $query
                        ->where(function (Builder $stored) use ($now, $staleBefore): void {
                            $stored
                                ->where('handling_status', TradingEngineEvent::STATUS_STORED)
                                ->where('received_at', '<=', $staleBefore)
                                ->where(function (Builder $lease) use ($now): void {
                                    $lease
                                        ->whereNull('next_handling_at')
                                        ->orWhere('next_handling_at', '<=', $now);
                                });
                        })
                        ->orWhere(function (Builder $retryable) use ($now): void {
                            $retryable
                                ->where('handling_status', TradingEngineEvent::STATUS_RETRYABLE)
                                ->whereNotNull('next_handling_at')
                                ->where('next_handling_at', '<=', $now);
                        })
                        ->orWhere(function (Builder $deferred) use ($now): void {
                            $deferred
                                ->where('handling_status', TradingEngineEvent::STATUS_DEFERRED)
                                ->whereNotNull('next_handling_at')
                                ->where('next_handling_at', '<=', $now)
                                ->whereExists(function ($link): void {
                                    $link
                                        ->selectRaw('1')
                                        ->from('trading_engine_opportunity_links')
                                        ->whereColumn(
                                            'trading_engine_opportunity_links.recorded_event_id',
                                            'trading_engine_event_inbox.causation_id',
                                        );
                                });
                        });
                })
                ->orderBy('id')
                ->limit($batchSize)
                ->lockForUpdate()
                ->get();

            if ($events->isEmpty()) {
                return [];
            }

            $ids = $events->pluck('id')->all();

            TradingEngineEvent::query()
                ->whereKey($ids)
                ->update([
                    'next_handling_at' => $leaseUntil,
                    'updated_at' => $now,
                ]);

            return $events->pluck('event_id')->all();
        }, 3);
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
