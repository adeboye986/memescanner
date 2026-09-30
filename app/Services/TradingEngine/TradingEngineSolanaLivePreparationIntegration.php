<?php

namespace App\Services\TradingEngine;

use App\Chain;
use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Enums\TradeOpportunityStatus;
use App\Models\SolanaSwapAttempt;
use App\Models\TradeOpportunity;
use App\Models\TradingEngineOpportunityEvaluation;
use App\Models\User;
use App\Services\SolanaOpportunityExecutionPolicy;
use App\Services\SolanaOpportunityPreparationService;
use App\Services\SolanaOpportunityReservationService;
use App\Services\SolanaPreparedAttemptIntegrity;
use Throwable;

class TradingEngineSolanaLivePreparationIntegration
{
    public function __construct(
        private TradingEngineLiveDecisionIntegration $decisions,
        private SolanaOpportunityReservationService $reservations,
        private SolanaOpportunityPreparationService $preparations,
        private SolanaOpportunityExecutionPolicy $executionPolicy,
        private SolanaPreparedAttemptIntegrity $integrity,
    ) {}

    public function attempt(TradeOpportunity|int $opportunity): ?SolanaSwapAttempt
    {
        if (! $this->executionPolicy->prerequisitesEnabled()) {
            return null;
        }

        try {
            $candidate = $opportunity instanceof TradeOpportunity ? $opportunity->fresh() : TradeOpportunity::query()->find($opportunity);
            if (! $candidate || $candidate->chain !== Chain::Solana || $candidate->execution_mode !== ExecutionMode::Live
                || $candidate->entry_mode !== EntryMode::Auto) {
                return null;
            }

            $existing = $candidate->solanaSwapAttempt;
            if ($existing) {
                if (! $this->executionPolicy->permitsConfirmFirstFlow($candidate)
                    || $existing->preparation_origin !== SolanaPreparedAttemptIntegrity::ENGINE_ORIGIN
                    || $existing->user_id !== $candidate->user_id || $existing->transaction_signature !== null) {
                    return null;
                }
                if ($existing->status === 'prepared') {
                    $this->integrity->assertValid($candidate, $existing);

                    return $existing->expires_at?->isFuture() ? $existing : null;
                }

                return $existing->status === 'reserved' ? $this->preparations->prepare($candidate, $candidate->user) : null;
            }

            if ($candidate->status !== TradeOpportunityStatus::Qualified) {
                return null;
            }
            $decision = $this->decisions->assess($candidate);
            if ($decision->decisionCode !== TradingEngineOpportunityDecision::WOULD_ENTER
                || $decision->evaluationId === null || $decision->engineOpportunityId === null) {
                return null;
            }
            $user = $candidate->user;
            if (! $user instanceof User) {
                return null;
            }

            $this->reservations->reserveAutomatic($candidate, $user);

            return $this->preparations->prepare($candidate->fresh(), $user);
        } catch (Throwable) {
            return null;
        }
    }

    public function attemptForProjectedEvaluation(string $eventId): ?SolanaSwapAttempt
    {
        if (! $this->executionPolicy->prerequisitesEnabled()) {
            return null;
        }
        try {
            $evaluation = TradingEngineOpportunityEvaluation::query()->where('evaluation_event_id', $eventId)->first();

            return $evaluation ? $this->attempt($evaluation->trade_opportunity_id) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
