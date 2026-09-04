<?php

namespace App\Services;

use App\Enums\ExecutionMode;
use App\Enums\TradeOpportunityStatus;
use App\Models\PaperPosition;
use App\Models\TradeOpportunity;
use App\Services\Trading\LiveTradeExecutor;
use App\Services\Trading\PaperTradeExecutor;
use RuntimeException;

class TradeExecutionManager
{
    public function __construct(
        private PaperTradeExecutor $paper,
        private LiveTradeExecutor $live,
        private ApplicationSettingsService $settings,
    ) {}

    public function execute(TradeOpportunity $opportunity, bool $sendNotification = true): ?PaperPosition
    {
        if ($this->settings->get('risk.kill_switch')) {
            throw new RuntimeException('Trading is disabled by the emergency kill switch.');
        }

        if ($opportunity->status === TradeOpportunityStatus::Executed) {
            return $opportunity->paper_position_id
                ? PaperPosition::query()->whereKey($opportunity->paper_position_id)->where('user_id', $opportunity->user_id)->first()
                : null;
        }

        $executor = match ($opportunity->execution_mode) {
            ExecutionMode::Paper => $this->paper,
            ExecutionMode::Live => $this->live,
        };
        try {
            $position = $executor->execute($opportunity, $sendNotification);
        } catch (\Throwable $exception) {
            $opportunity->update([
                'status' => TradeOpportunityStatus::Failed,
                'execution_data' => [
                    'executor' => $opportunity->execution_mode->value,
                    'reason' => $opportunity->execution_mode === ExecutionMode::Live
                        ? 'live_execution_disabled'
                        : 'execution_failed',
                ],
            ]);

            throw $exception;
        }

        $opportunity->update([
            'status' => TradeOpportunityStatus::Executed,
            'paper_position_id' => $position?->id,
            'executed_at' => now(),
            'execution_data' => ['executor' => $opportunity->execution_mode->value],
        ]);

        return $position;
    }
}
