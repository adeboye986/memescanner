<?php

namespace App\Services\TradingEngine;

use App\Enums\EntryMode;
use App\Models\TradeOpportunity;
use App\Models\TradingEngineOpportunityEvaluation;
use App\Models\TradingEngineOpportunityLink;
use App\Models\UserTradingPreference;
use App\Services\ApplicationSettingsService;
use Throwable;

class TradingEngineOpportunityDecisionPolicy
{
    public function __construct(
        private TradingEngineEvaluationConsumptionPolicy $consumptionPolicy,
        private ApplicationSettingsService $settings,
    ) {}

    public function assess(
        TradeOpportunity $opportunity,
        ?TradingEngineOpportunityLink $link,
        ?TradingEngineOpportunityEvaluation $evaluation,
    ): TradingEngineOpportunityDecision {
        $consumption = $this->consumptionPolicy->assess($opportunity, $link, $evaluation);

        if (! $consumption->eligible) {
            return TradingEngineOpportunityDecision::consumptionIneligible($consumption);
        }

        if (config('services.trading_engine.decision_boundary_enabled', false) !== true) {
            return TradingEngineOpportunityDecision::wouldReject(
                $consumption,
                'DECISION_BOUNDARY_DISABLED',
            );
        }

        try {
            if ($this->settings->get('risk.kill_switch')) {
                return TradingEngineOpportunityDecision::wouldReject(
                    $consumption,
                    'KILL_SWITCH_ACTIVE',
                );
            }

            $tradingEnabled = UserTradingPreference::query()
                ->where('user_id', $opportunity->user_id)
                ->value('trading_enabled');

            if ($tradingEnabled !== null && ! (bool) $tradingEnabled) {
                return TradingEngineOpportunityDecision::wouldReject(
                    $consumption,
                    'USER_TRADING_DISABLED',
                );
            }

            return match ($opportunity->entry_mode) {
                EntryMode::Auto => TradingEngineOpportunityDecision::wouldEnter(
                    $consumption,
                    'AUTO_ENTRY_CONFIGURED',
                ),
                EntryMode::Confirm => TradingEngineOpportunityDecision::wouldHold(
                    $consumption,
                    'CONFIRMATION_REQUIRED',
                ),
                EntryMode::Signal => TradingEngineOpportunityDecision::wouldHold(
                    $consumption,
                    'SIGNAL_ONLY',
                ),
            };
        } catch (Throwable) {
            return TradingEngineOpportunityDecision::wouldReject(
                $consumption,
                'LARAVEL_POLICY_INPUT_UNAVAILABLE',
            );
        }
    }
}
