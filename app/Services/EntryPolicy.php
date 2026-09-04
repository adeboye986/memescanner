<?php

namespace App\Services;

use App\Enums\EntryMode;
use App\Enums\TradeOpportunityStatus;
use App\Models\PaperPosition;
use App\Models\TradeOpportunity;

class EntryPolicy
{
    public function __construct(
        private ApplicationSettingsService $settings,
        private TradeExecutionManager $executions,
        private UserTradingPreferenceService $preferences,
    ) {}

    public function apply(TradeOpportunity $opportunity): ?PaperPosition
    {
        if ($this->settings->get('risk.kill_switch') || ($opportunity->user_id && ! $this->preferences->forUser($opportunity->user)->trading_enabled)) {
            $opportunity->update(['status' => TradeOpportunityStatus::Ignored]);

            return null;
        }

        if ($opportunity->entry_mode === EntryMode::Confirm) {
            $opportunity->update(['status' => TradeOpportunityStatus::PendingConfirmation]);

            return null;
        }

        return $opportunity->entry_mode === EntryMode::Auto
            ? $this->executions->execute($opportunity)
            : null;
    }
}
