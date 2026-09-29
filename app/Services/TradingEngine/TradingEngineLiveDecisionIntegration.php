<?php

namespace App\Services\TradingEngine;

use App\Enums\ExecutionMode;
use App\Enums\TradeOpportunityStatus;
use App\Models\TradeOpportunity;
use App\Models\TradingEngineOpportunityEvaluation;
use App\Models\TradingEngineOpportunityLink;
use Illuminate\Support\Facades\DB;
use Throwable;

class TradingEngineLiveDecisionIntegration
{
    public function __construct(private TradingEngineOpportunityDecisionPolicy $decisions) {}

    public function assess(TradeOpportunity|int $opportunity): TradingEngineOpportunityDecision
    {
        if (config('services.trading_engine.live_decision_integration_enabled', false) !== true) {
            return $this->reject('LIVE_DECISION_INTEGRATION_DISABLED');
        }

        try {
            return DB::transaction(function () use ($opportunity): TradingEngineOpportunityDecision {
                $opportunityId = $opportunity instanceof TradeOpportunity
                    ? $opportunity->getKey()
                    : $opportunity;
                $locked = TradeOpportunity::query()
                    ->lockForUpdate()
                    ->find($opportunityId);

                if (! $locked instanceof TradeOpportunity) {
                    return $this->reject('LIVE_OPPORTUNITY_MISSING');
                }

                if ($locked->execution_mode !== ExecutionMode::Live) {
                    return $this->reject('LIVE_EXECUTION_MODE_REQUIRED');
                }

                if (! in_array($locked->status, [
                    TradeOpportunityStatus::Qualified,
                    TradeOpportunityStatus::PendingConfirmation,
                ], true)) {
                    return $this->reject('LIVE_OPPORTUNITY_STATE_INELIGIBLE');
                }

                $link = TradingEngineOpportunityLink::query()
                    ->where('trade_opportunity_id', $locked->getKey())
                    ->first();
                $evaluation = $link instanceof TradingEngineOpportunityLink
                    ? TradingEngineOpportunityEvaluation::query()
                        ->where('opportunity_link_id', $link->getKey())
                        ->where('policy_key', TradingEngineEvaluationConsumptionPolicy::APPROVED_POLICY_KEY)
                        ->where('policy_version', TradingEngineEvaluationConsumptionPolicy::APPROVED_POLICY_VERSION)
                        ->first()
                    : null;

                return $this->decisions->assess($locked, $link, $evaluation);
            }, 3);
        } catch (Throwable) {
            return $this->reject('LIVE_DECISION_UNAVAILABLE');
        }
    }

    public function assessForProjectedEvaluation(string $eventId): ?TradingEngineOpportunityDecision
    {
        if (config('services.trading_engine.live_decision_integration_enabled', false) !== true) {
            return null;
        }

        try {
            $evaluation = TradingEngineOpportunityEvaluation::query()
                ->where('evaluation_event_id', $eventId)
                ->first();

            if (! $evaluation instanceof TradingEngineOpportunityEvaluation) {
                return null;
            }

            return $this->assess($evaluation->trade_opportunity_id);
        } catch (Throwable) {
            return $this->reject('LIVE_DECISION_UNAVAILABLE');
        }
    }

    private function reject(string $reasonCode): TradingEngineOpportunityDecision
    {
        return TradingEngineOpportunityDecision::consumptionIneligible(
            TradingEngineEvaluationConsumptionDecision::ineligible($reasonCode),
        );
    }
}
