<?php

namespace App\Services;

use App\Chain;
use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Enums\TradeOpportunityStatus;
use App\Models\SolanaSwapAttempt;
use App\Models\TradeOpportunity;
use App\Models\TradeOpportunityEvent;
use App\Models\User;
use App\Services\TradingEngine\TradingEngineLiveDecisionIntegration;
use App\Services\TradingEngine\TradingEngineOpportunityDecision;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class SolanaOpportunityReservationService
{
    public function __construct(
        private ApplicationSettingsService $settings,
        private UserTradingPreferenceService $preferences,
        private SolanaOpportunityExecutionPolicy $executionPolicy,
        private TradingEngineLiveDecisionIntegration $liveDecisions,
        private SolanaQuoteLimitService $limits,
        private SolanaOpportunityFreshness $freshness,
    ) {}

    public function reserveAutomatic(TradeOpportunity $opportunity, User $actor): SolanaSwapAttempt
    {
        if (! $this->executionPolicy->prerequisitesEnabled()) {
            throw new DomainException('Automatic Solana LIVE preparation is disabled.');
        }

        $binding = null;
        try {
            return DB::transaction(function () use ($opportunity, $actor, &$binding): SolanaSwapAttempt {
                $locked = TradeOpportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
                if ($locked->user_id !== $actor->id || $locked->chain !== Chain::Solana
                    || $locked->execution_mode !== ExecutionMode::Live || $locked->entry_mode !== EntryMode::Auto) {
                    throw new DomainException('This Solana opportunity cannot reserve automatic LIVE execution.');
                }
                if (! in_array($locked->status, [TradeOpportunityStatus::Qualified, TradeOpportunityStatus::Executing], true)) {
                    throw new DomainException('This opportunity cannot start LIVE execution from its current state.');
                }

                $decision = $this->liveDecisions->assess($locked);
                if ($decision->decisionCode !== TradingEngineOpportunityDecision::WOULD_ENTER
                    || $decision->evaluationId === null || $decision->engineOpportunityId === null) {
                    throw new DomainException('The verified LIVE decision does not permit Solana preparation.');
                }

                $preference = $this->preferences->forUser($actor);
                $preference = $preference->newQuery()->whereKey($preference->id)->lockForUpdate()->firstOrFail();
                if ($preference->execution_mode !== ExecutionMode::Live || $preference->entry_mode !== EntryMode::Auto
                    || ! $preference->trading_enabled || $this->settings->get('risk.kill_switch')) {
                    throw new DomainException('New Solana LIVE execution is disabled.');
                }

                $wallet = $actor->connectedWallets()->where('chain', Chain::Solana->value)
                    ->whereNotNull('verified_at')->whereNull('disconnected_at')->lockForUpdate()->first();
                if (! $wallet) {
                    throw new DomainException('An active verified Solana wallet is required.');
                }

                $slippage = min(500, max(1, (int) round((float) $this->settings->get('risk.max_slippage_percent') * 100)));
                $binding = [
                    'trade_opportunity_id' => $locked->id,
                    'user_id' => $actor->id,
                    'connected_wallet_id' => $wallet->id,
                    'wallet_address' => $wallet->address,
                    'input_mint' => SolanaSwapQuoteService::SOL_MINT,
                    'output_mint' => $locked->address,
                    'input_amount_lamports' => $this->limits->maximumLamports(),
                    'slippage_bps' => $slippage,
                    'network' => SolanaPreparedAttemptIntegrity::NETWORK,
                    'preparation_origin' => SolanaPreparedAttemptIntegrity::ENGINE_ORIGIN,
                ];

                $existing = $locked->solanaSwapAttempt;
                if ($existing) {
                    if ($locked->status !== TradeOpportunityStatus::Executing || ! $this->matches($existing, $binding)) {
                        throw new DomainException('This opportunity already has a different Solana LIVE reservation.');
                    }

                    return $existing;
                }
                if ($locked->status !== TradeOpportunityStatus::Qualified) {
                    throw new DomainException('This opportunity already has LIVE execution in progress.');
                }

                $this->freshness->assertFresh($locked);
                $locked->update([
                    'status' => TradeOpportunityStatus::Executing,
                    'execution_mode' => ExecutionMode::Live,
                    'execution_data' => [
                        'executor' => 'live', 'stage' => 'reserved',
                        'source' => SolanaOpportunityExecutionPolicy::ENGINE_AUTO_SOURCE,
                        'engine_evaluation_id' => $decision->evaluationId,
                        'engine_opportunity_id' => $decision->engineOpportunityId,
                    ],
                ]);
                $attempt = SolanaSwapAttempt::query()->create([
                    ...$binding, 'status' => 'reserved', 'request_id' => null,
                    'message_hash' => null, 'prepared_transaction' => null, 'expires_at' => null,
                ]);
                $locked->update(['execution_data' => [...$locked->execution_data, 'solana_swap_attempt_id' => $attempt->id]]);
                TradeOpportunityEvent::query()->create([
                    'trade_opportunity_id' => $locked->id, 'user_id' => $actor->id,
                    'action' => 'live_execution_reserved', 'from_status' => TradeOpportunityStatus::Qualified->value,
                    'to_status' => TradeOpportunityStatus::Executing->value,
                    'metadata' => ['solana_swap_attempt_id' => $attempt->id],
                ]);

                return $attempt;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if ($binding === null) {
                throw $exception;
            }

            return DB::transaction(function () use ($binding): SolanaSwapAttempt {
                $locked = TradeOpportunity::query()->lockForUpdate()->find($binding['trade_opportunity_id']);
                $existing = $locked?->solanaSwapAttempt()->lockForUpdate()->first();
                if (! $locked || $locked->status !== TradeOpportunityStatus::Executing
                    || ! $existing || ! $this->matches($existing, $binding)) {
                    throw new DomainException('This opportunity has a conflicting Solana LIVE reservation.');
                }

                return $existing;
            });
        }
    }

    /** @param array<string, int|string> $binding */
    private function matches(SolanaSwapAttempt $attempt, array $binding): bool
    {
        foreach ($binding as $field => $value) {
            $actual = in_array($field, ['input_amount_lamports', 'slippage_bps'], true)
                ? (int) $attempt->{$field}
                : $attempt->{$field};
            if ($actual !== $value) {
                return false;
            }
        }

        return true;
    }
}
