<?php

namespace App\Services\TradingEngine;

use App\Chain;
use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Enums\TradeOpportunityStatus;
use App\Models\EthereumSwapAttempt;
use App\Models\TradeOpportunity;
use App\Models\TradingEngineOpportunityEvaluation;
use App\Models\User;
use App\Services\EthereumOpportunityExecutionPolicy;
use App\Services\EthereumOpportunityPreparationService;
use App\Services\EthereumOpportunityReservationService;
use App\Services\EthereumQuoteLimitService;
use App\Services\EthereumSwapInputRules;
use Throwable;

class TradingEngineLivePreparationIntegration
{
    public function __construct(
        private TradingEngineLiveDecisionIntegration $decisions,
        private EthereumOpportunityReservationService $reservations,
        private EthereumOpportunityPreparationService $preparations,
        private EthereumOpportunityExecutionPolicy $executionPolicy,
        private EthereumQuoteLimitService $limits,
        private EthereumSwapInputRules $inputs,
    ) {}

    public function attempt(TradeOpportunity|int $opportunity): ?EthereumSwapAttempt
    {
        if (! $this->executionPolicy->prerequisitesEnabled()) {
            return null;
        }

        try {
            $candidate = $opportunity instanceof TradeOpportunity
                ? $opportunity->fresh()
                : TradeOpportunity::query()->find($opportunity);

            if (! $candidate instanceof TradeOpportunity
                || $candidate->chain !== Chain::Ethereum
                || $candidate->execution_mode !== ExecutionMode::Live
                || $candidate->entry_mode !== EntryMode::Auto) {
                return null;
            }

            $existing = $candidate->ethereumSwapAttempt;
            if ($existing instanceof EthereumSwapAttempt) {
                if (! $this->executionPolicy->permitsConfirmFirstFlow($candidate)
                    || $existing->user_id !== $candidate->user_id
                    || $existing->transaction_hash !== null
                    || $existing->submitted_at !== null) {
                    return null;
                }

                if ($existing->status === 'prepared') {
                    return $existing->expires_at?->isFuture() ? $existing : null;
                }

                if ($existing->status !== 'reserved') {
                    return null;
                }

                return $this->preparations->prepare($candidate, $candidate->user);
            }

            if ($candidate->status !== TradeOpportunityStatus::Qualified) {
                return null;
            }

            $decision = $this->decisions->assess($candidate);
            if ($decision->decisionCode !== TradingEngineOpportunityDecision::WOULD_ENTER
                || $decision->evaluationId === null
                || $decision->engineOpportunityId === null) {
                return null;
            }

            $user = $candidate->user;
            if (! $user instanceof User) {
                return null;
            }

            $this->reservations->reserveAutomatic($candidate, $user, [
                'sell_amount_wei' => $this->limits->maximumWei(),
                'slippage_bps' => $this->inputs->maximumSlippageBps(),
            ]);

            return $this->preparations->prepare($candidate->fresh(), $user);
        } catch (Throwable) {
            return null;
        }
    }

    public function attemptForProjectedEvaluation(string $eventId): ?EthereumSwapAttempt
    {
        if (! $this->executionPolicy->prerequisitesEnabled()) {
            return null;
        }

        try {
            $evaluation = TradingEngineOpportunityEvaluation::query()
                ->where('evaluation_event_id', $eventId)
                ->first();

            return $evaluation instanceof TradingEngineOpportunityEvaluation
                ? $this->attempt($evaluation->trade_opportunity_id)
                : null;
        } catch (Throwable) {
            return null;
        }
    }
}
