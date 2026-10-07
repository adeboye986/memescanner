<?php

namespace App\Services\TradingEngine;

use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Enums\TradeOpportunityStatus;
use App\Models\PaperPosition;
use App\Models\TradeOpportunity;
use App\Models\TradingEngineOpportunityEvaluation;
use App\Models\TradingEngineOpportunityLink;
use App\Services\TradeExecutionManager;
use App\Services\Trading\PaperTradeExecutor;
use Illuminate\Support\Facades\DB;

class TradingEnginePaperDecisionIntegration
{
    public function __construct(
        private TradingEngineOpportunityDecisionPolicy $decisions,
        private TradingEnginePaperEntryIntegration $paperEntries,
        private TradeExecutionManager $executions,
        private PaperTradeExecutor $paperExecutor,
    ) {}

    public function attempt(TradeOpportunity $opportunity): ?PaperPosition
    {
        if (config('services.trading_engine.paper_decision_integration_enabled', false) !== true) {
            return null;
        }

        /** @var array{position: ?PaperPosition, notify: bool} $result */
        $result = DB::transaction(function () use ($opportunity): array {
            $locked = TradeOpportunity::query()
                ->lockForUpdate()
                ->find($opportunity->getKey());

            if (! $locked instanceof TradeOpportunity
                || $locked->execution_mode !== ExecutionMode::Paper
                || $locked->entry_mode !== EntryMode::Auto) {
                return ['position' => null, 'notify' => false];
            }

            if ($locked->status === TradeOpportunityStatus::Executed) {
                return [
                    'position' => $locked->paper_position_id
                        ? PaperPosition::query()
                            ->whereKey($locked->paper_position_id)
                            ->where('user_id', $locked->user_id)
                            ->first()
                        : null,
                    'notify' => false,
                ];
            }

            if ($locked->status !== TradeOpportunityStatus::Qualified) {
                return ['position' => null, 'notify' => false];
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
            $decision = $this->decisions->assess($locked, $link, $evaluation);

            if ($decision->decisionCode !== TradingEngineOpportunityDecision::WOULD_ENTER) {
                return ['position' => null, 'notify' => false];
            }

            if ($this->paperEntries->routes($locked)) {
                $this->paperEntries->submit($locked, $link, $evaluation);

                return ['position' => null, 'notify' => false];
            }

            $position = $this->executions->executePaper($locked, false);

            return [
                'position' => $position,
                'notify' => $position?->wasRecentlyCreated === true,
            ];
        }, 3);

        if ($result['notify']
            && $result['position'] instanceof PaperPosition
            && (bool) data_get($opportunity->qualification_data, 'send_notification', true)) {
            $this->paperExecutor->sendNotification($result['position']);
        }

        return $result['position'];
    }

    public function attemptForProjectedEvaluation(string $eventId): ?PaperPosition
    {
        if (config('services.trading_engine.paper_decision_integration_enabled', false) !== true) {
            return null;
        }

        $evaluation = TradingEngineOpportunityEvaluation::query()
            ->where('evaluation_event_id', $eventId)
            ->first();

        if (! $evaluation instanceof TradingEngineOpportunityEvaluation) {
            return null;
        }

        $opportunity = TradeOpportunity::query()->find($evaluation->trade_opportunity_id);

        return $opportunity instanceof TradeOpportunity
            ? $this->attempt($opportunity)
            : null;
    }
}
