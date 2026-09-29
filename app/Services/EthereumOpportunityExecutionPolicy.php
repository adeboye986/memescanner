<?php

namespace App\Services;

use App\Enums\EntryMode;
use App\Models\TradeOpportunity;

class EthereumOpportunityExecutionPolicy
{
    public const ENGINE_AUTO_SOURCE = 'trading_engine_live_preparation';

    public function permitsConfirmFirstFlow(TradeOpportunity $opportunity): bool
    {
        if ($opportunity->entry_mode === EntryMode::Confirm) {
            return true;
        }

        return $this->prerequisitesEnabled() && $this->isEngineAutomatic($opportunity);
    }

    public function permitsSubmittedEvidence(TradeOpportunity $opportunity): bool
    {
        return $opportunity->entry_mode === EntryMode::Confirm
            || $this->isEngineAutomatic($opportunity);
    }

    private function isEngineAutomatic(TradeOpportunity $opportunity): bool
    {
        return $opportunity->entry_mode === EntryMode::Auto
            && data_get($opportunity->execution_data, 'source') === self::ENGINE_AUTO_SOURCE
            && is_string(data_get($opportunity->execution_data, 'engine_evaluation_id'))
            && is_string(data_get($opportunity->execution_data, 'engine_opportunity_id'));
    }

    public function prerequisitesEnabled(): bool
    {
        return config('services.trading_engine.enabled', false) === true
            && config('services.trading_engine.opportunity_projection_enabled', false) === true
            && config('services.trading_engine.evaluation_consumption_enabled', false) === true
            && config('services.trading_engine.decision_boundary_enabled', false) === true
            && config('services.trading_engine.live_decision_integration_enabled', false) === true
            && config('services.trading_engine.live_preparation_enabled', false) === true;
    }
}
