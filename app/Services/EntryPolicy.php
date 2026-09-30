<?php

namespace App\Services;

use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Enums\TradeOpportunityStatus;
use App\Models\PaperPosition;
use App\Models\TradeOpportunity;
use App\Services\TradingEngine\TradingEngineLiveDecisionIntegration;
use App\Services\TradingEngine\TradingEngineLivePreparationIntegration;
use App\Services\TradingEngine\TradingEnginePaperDecisionIntegration;
use App\Services\TradingEngine\TradingEngineSolanaLivePreparationIntegration;
use Throwable;

class EntryPolicy
{
    public function __construct(
        private ApplicationSettingsService $settings,
        private TradeExecutionManager $executions,
        private UserTradingPreferenceService $preferences,
        private TradingEnginePaperDecisionIntegration $paperDecisionIntegration,
        private TradingEngineLiveDecisionIntegration $liveDecisionIntegration,
        private TradingEngineLivePreparationIntegration $livePreparationIntegration,
        private TradingEngineSolanaLivePreparationIntegration $solanaLivePreparationIntegration,
    ) {}

    public function apply(TradeOpportunity $opportunity): ?PaperPosition
    {
        $useLiveDecisionIntegration = $opportunity->execution_mode === ExecutionMode::Live
            && config('services.trading_engine.live_decision_integration_enabled', false) === true;

        if ($useLiveDecisionIntegration) {
            try {
                $this->liveDecisionIntegration->assess($opportunity);
                $this->livePreparationIntegration->attempt($opportunity);
                $this->solanaLivePreparationIntegration->attempt($opportunity);
            } catch (Throwable) {
                return null;
            }
        }

        if ($this->settings->get('risk.kill_switch') || ($opportunity->user_id && ! $this->preferences->forUser($opportunity->user)->trading_enabled)) {
            $opportunity->update(['status' => TradeOpportunityStatus::Ignored]);

            return null;
        }

        if ($opportunity->entry_mode === EntryMode::Confirm) {
            $opportunity->update(['status' => TradeOpportunityStatus::PendingConfirmation]);

            return null;
        }

        if ($useLiveDecisionIntegration) {
            return null;
        }

        if ($opportunity->entry_mode !== EntryMode::Auto) {
            return null;
        }

        if ($opportunity->execution_mode === ExecutionMode::Paper
            && config('services.trading_engine.paper_decision_integration_enabled', false) === true) {
            try {
                return $this->paperDecisionIntegration->attempt($opportunity);
            } catch (Throwable $exception) {
                report($exception);

                return null;
            }
        }

        return $this->executions->execute($opportunity);
    }
}
