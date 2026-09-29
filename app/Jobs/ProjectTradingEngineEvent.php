<?php

namespace App\Jobs;

use App\Services\TradingEngine\TradingEngineOpportunityProjector;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProjectTradingEngineEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $eventId) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [5, 30, 120, 300];
    }

    public function handle(TradingEngineOpportunityProjector $projector): void
    {
        if (config('services.trading_engine.enabled', false) !== true
            || config('services.trading_engine.opportunity_projection_enabled', false) !== true) {
            return;
        }

        $result = $projector->project($this->eventId);

        foreach ($result['dependent_event_ids'] as $dependentEventId) {
            $projector->project($dependentEventId);
        }
    }
}
