<?php

namespace App\Services\TradingEngine;

use App\Jobs\ProjectTradingEngineEvent;
use App\Models\TradingEngineEvent;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Throwable;

class TradingEngineProjectionRecovery
{
    public function __construct(
        private Dispatcher $dispatcher,
        private TradingEngineOpportunityProjector $projector,
        private TradingEngineProjectionEligibility $eligibility,
    ) {}

    /**
     * @return array{enabled: bool, selected: int, dispatched: int, failed: int}
     */
    public function recover(): array
    {
        if (! $this->eligibility->enabled()) {
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
            if ($this->dispatch($eventId)) {
                $dispatched++;
            } else {
                $failed++;
            }
        }

        return [
            'enabled' => true,
            'selected' => count($eventIds),
            'dispatched' => $dispatched,
            'failed' => $failed,
        ];
    }

    /**
     * @return array{status: string, error_code: string|null}
     */
    public function retry(string $eventId): array
    {
        if (! $this->eligibility->enabled()) {
            return [
                'status' => 'blocked',
                'error_code' => 'PROJECTION_RECOVERY_DISABLED',
            ];
        }

        $lease = DB::transaction(function () use ($eventId): array {
            $now = now();
            $event = TradingEngineEvent::query()
                ->where('event_id', $eventId)
                ->lockForUpdate()
                ->first();

            if (! $event) {
                return ['acquired' => false, 'error_code' => 'PROJECTION_EVENT_NOT_FOUND'];
            }

            $blockCode = $this->eligibility->manualRetryBlockCode($event, $now);

            if ($blockCode !== null) {
                return ['acquired' => false, 'error_code' => $blockCode];
            }

            $claim = TradingEngineEvent::query()
                ->whereKey($event->getKey())
                ->where('handling_status', $event->handling_status);
            $originalNextHandlingAt = $event->getRawOriginal('next_handling_at');

            if ($originalNextHandlingAt === null) {
                $claim->whereNull('next_handling_at');
            } else {
                $claim->where('next_handling_at', $originalNextHandlingAt);
            }

            $acquired = $claim->update([
                'next_handling_at' => $this->eligibility->leaseUntil($now),
                'updated_at' => $now,
            ]) === 1;

            return [
                'acquired' => $acquired,
                'error_code' => $acquired ? null : 'PROJECTION_LEASE_NOT_ACQUIRED',
            ];
        }, 3);

        if (! $lease['acquired']) {
            return [
                'status' => 'blocked',
                'error_code' => $lease['error_code'],
            ];
        }

        if (! $this->dispatch($eventId)) {
            return [
                'status' => 'failed',
                'error_code' => 'PROJECTION_DISPATCH_FAILED',
            ];
        }

        return [
            'status' => 'dispatched',
            'error_code' => null,
        ];
    }

    /** @return array<int, string> */
    private function leaseEligibleEventIds(): array
    {
        $now = now();
        $leaseUntil = $this->eligibility->leaseUntil($now);
        $batchSize = $this->eligibility->batchSize();

        return DB::transaction(function () use ($batchSize, $leaseUntil, $now): array {
            $query = $this->eligibility->projectableQuery()
                ->select(['id', 'event_id']);
            $this->eligibility->applyAutomaticEligibility($query, $now);

            $events = $query
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

    private function dispatch(string $eventId): bool
    {
        try {
            $this->dispatcher->dispatch(new ProjectTradingEngineEvent($eventId));

            return true;
        } catch (Throwable) {
            $this->projector->markDispatchFailure($eventId);

            return false;
        }
    }
}
