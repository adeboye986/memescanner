<?php

namespace App\Jobs;

use App\Services\TradingEngine\TradingEngineLiveDecisionIntegration;
use App\Services\TradingEngine\TradingEngineLivePreparationIntegration;
use App\Services\TradingEngine\TradingEngineOpportunityProjector;
use App\Services\TradingEngine\TradingEnginePaperDecisionIntegration;
use App\Services\TradingEngine\TradingEngineSolanaLivePreparationIntegration;
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

    public function handle(
        TradingEngineOpportunityProjector $projector,
        ?TradingEnginePaperDecisionIntegration $paperDecisionIntegration = null,
        ?TradingEngineLiveDecisionIntegration $liveDecisionIntegration = null,
        ?TradingEngineLivePreparationIntegration $livePreparationIntegration = null,
        ?TradingEngineSolanaLivePreparationIntegration $solanaLivePreparationIntegration = null,
    ): void {
        if (config('services.trading_engine.enabled', false) !== true
            || (config('services.trading_engine.opportunity_projection_enabled', false) !== true
                && config('services.trading_engine.paper_entry_integration_enabled', false) !== true
                && config('services.trading_engine.paper_financial_lifecycle_enabled', false) !== true
                && ! (config('services.trading_engine.paper_lifecycle_integration_enabled', false) === true
                    && config('services.trading_engine.paper_lifecycle_authoritative_enabled', false) === true))) {
            return;
        }

        $paperDecisionIntegration ??= app(TradingEnginePaperDecisionIntegration::class);
        $liveDecisionIntegration ??= app(TradingEngineLiveDecisionIntegration::class);
        $livePreparationIntegration ??= app(TradingEngineLivePreparationIntegration::class);
        $solanaLivePreparationIntegration ??= app(TradingEngineSolanaLivePreparationIntegration::class);
        $result = $projector->project($this->eventId);
        $paperDecisionIntegration->attemptForProjectedEvaluation($this->eventId);
        $liveDecisionIntegration->assessForProjectedEvaluation($this->eventId);
        $livePreparationIntegration->attemptForProjectedEvaluation($this->eventId);
        $solanaLivePreparationIntegration->attemptForProjectedEvaluation($this->eventId);

        foreach ($result['dependent_event_ids'] as $dependentEventId) {
            $projector->project($dependentEventId);
            $paperDecisionIntegration->attemptForProjectedEvaluation($dependentEventId);
            $liveDecisionIntegration->assessForProjectedEvaluation($dependentEventId);
            $livePreparationIntegration->attemptForProjectedEvaluation($dependentEventId);
            $solanaLivePreparationIntegration->attemptForProjectedEvaluation($dependentEventId);
        }
    }
}
