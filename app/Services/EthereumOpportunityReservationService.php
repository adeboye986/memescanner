<?php

namespace App\Services;

use App\Chain;
use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Enums\TradeOpportunityStatus;
use App\Models\EthereumSwapAttempt;
use App\Models\TradeOpportunity;
use App\Models\TradeOpportunityEvent;
use App\Models\User;
use App\Services\TradingEngine\TradingEngineLiveDecisionIntegration;
use App\Services\TradingEngine\TradingEngineOpportunityDecision;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class EthereumOpportunityReservationService
{
    public function __construct(
        private ApplicationSettingsService $settings,
        private UserTradingPreferenceService $preferences,
        private EthereumSwapInputRules $inputs,
        private EthereumOpportunityExecutionPolicy $executionPolicy,
        private TradingEngineLiveDecisionIntegration $liveDecisions,
    ) {}

    /** @param array{sell_amount_wei?: mixed, slippage_bps?: mixed} $input */
    public function reserve(TradeOpportunity $opportunity, User $actor, array $input): EthereumSwapAttempt
    {
        return $this->reserveForEntryMode($opportunity, $actor, $input, EntryMode::Confirm);
    }

    /** @param array{sell_amount_wei?: mixed, slippage_bps?: mixed} $input */
    public function reserveAutomatic(TradeOpportunity $opportunity, User $actor, array $input): EthereumSwapAttempt
    {
        if (! $this->executionPolicy->prerequisitesEnabled()) {
            throw new DomainException('Automatic LIVE preparation is disabled.');
        }

        return $this->reserveForEntryMode($opportunity, $actor, $input, EntryMode::Auto);
    }

    /** @param array{sell_amount_wei?: mixed, slippage_bps?: mixed} $input */
    private function reserveForEntryMode(TradeOpportunity $opportunity, User $actor, array $input, EntryMode $entryMode): EthereumSwapAttempt
    {
        $binding = null;
        $initialStatus = $entryMode === EntryMode::Auto
            ? TradeOpportunityStatus::Qualified
            : TradeOpportunityStatus::PendingConfirmation;

        try {
            return DB::transaction(function () use ($opportunity, $actor, $input, $entryMode, $initialStatus, &$binding): EthereumSwapAttempt {
                $locked = TradeOpportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
                if ($locked->user_id !== $actor->id) {
                    throw new DomainException('This live opportunity must belong to your account.');
                }
                if ($locked->chain !== Chain::Ethereum || $locked->entry_mode !== $entryMode) {
                    throw new DomainException('This Ethereum opportunity cannot reserve the requested live execution.');
                }
                if (! in_array($locked->status, [$initialStatus, TradeOpportunityStatus::Executing], true)) {
                    throw new DomainException('This opportunity cannot start a live execution from its current state.');
                }

                $decision = null;
                if ($entryMode === EntryMode::Auto) {
                    $decision = $this->liveDecisions->assess($locked);
                    if ($decision->decisionCode !== TradingEngineOpportunityDecision::WOULD_ENTER
                        || $decision->evaluationId === null || $decision->engineOpportunityId === null) {
                        throw new DomainException('The verified LIVE decision does not permit preparation.');
                    }
                }

                $preference = $this->preferences->forUser($actor);
                $preference = $preference->newQuery()->whereKey($preference->id)->lockForUpdate()->firstOrFail();
                if ($preference->execution_mode !== ExecutionMode::Live || $preference->entry_mode !== $entryMode) {
                    throw new DomainException('The required live entry mode is not enabled for this account.');
                }
                if (! $preference->trading_enabled || $this->settings->get('risk.kill_switch')) {
                    throw new DomainException('New live execution is disabled.');
                }

                $validated = Validator::make([
                    ...$input,
                    'buy_token' => $locked->address,
                ], $this->inputs->rules())->validate();

                $wallet = $actor->connectedWallets()->verifiedEthereum()->lockForUpdate()->first();
                if (! $wallet) {
                    throw new DomainException('An active verified Ethereum wallet is required.');
                }

                $binding = [
                    'trade_opportunity_id' => $locked->id,
                    'user_id' => $actor->id,
                    'connected_wallet_id' => $wallet->id,
                    'wallet_address' => strtolower($wallet->address),
                    'buy_token' => strtolower($validated['buy_token']),
                    'sell_amount_wei' => $validated['sell_amount_wei'],
                    'slippage_bps' => (int) $validated['slippage_bps'],
                ];
                $existing = $locked->ethereumSwapAttempt;
                if ($existing) {
                    if ($locked->status === $initialStatus && $existing->status === 'released'
                        && $existing->transaction_payload === null && $existing->transaction_hash === null
                        && $existing->quote_id === null && $existing->expires_at === null
                        && $this->matchesBinding($existing, $binding)) {
                        app(EthereumOpportunityFreshness::class)->assertFresh($locked);
                        $existing->update(['status' => 'reserved', 'failure_reason' => null, 'updated_at' => now()]);
                        $locked->update(['status' => TradeOpportunityStatus::Executing,
                            'execution_data' => $this->executionData($existing->id, $decision)]);
                        TradeOpportunityEvent::query()->create(['trade_opportunity_id' => $locked->id, 'user_id' => $actor->id,
                            'action' => 'live_execution_reserved', 'from_status' => $initialStatus->value, 'to_status' => 'executing',
                            'metadata' => ['ethereum_swap_attempt_id' => $existing->id, 'reapproved' => true]]);

                        return $existing;
                    }
                    if ($locked->status !== TradeOpportunityStatus::Executing || ! $this->matchesBinding($existing, $binding)) {
                        throw new DomainException('This opportunity already has a different live execution reservation.');
                    }

                    return $existing;
                }
                if ($locked->status !== $initialStatus) {
                    throw new DomainException('This opportunity already has a live execution in progress.');
                }

                app(EthereumOpportunityFreshness::class)->assertFresh($locked);

                $locked->update([
                    'status' => TradeOpportunityStatus::Executing,
                    'execution_mode' => ExecutionMode::Live,
                    'execution_data' => $this->executionData(null, $decision),
                ]);
                $attempt = EthereumSwapAttempt::query()->create([
                    ...$binding,
                    'status' => 'reserved',
                    'transaction_payload' => null,
                    'expires_at' => null,
                ]);
                $locked->update(['execution_data' => $this->executionData($attempt->id, $decision)]);
                TradeOpportunityEvent::query()->create([
                    'trade_opportunity_id' => $locked->id,
                    'user_id' => $actor->id,
                    'action' => 'live_execution_reserved',
                    'from_status' => $initialStatus->value,
                    'to_status' => TradeOpportunityStatus::Executing->value,
                    'metadata' => ['ethereum_swap_attempt_id' => $attempt->id],
                ]);

                return $attempt;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if ($binding === null || preg_match('/^insert into [`"]ethereum_swap_attempts[`"]/i', $exception->getSql()) !== 1
                || ($exception->index !== 'ethereum_swap_attempts_trade_opportunity_id_unique'
                && $exception->columns !== ['trade_opportunity_id'])) {
                throw $exception;
            }

            return DB::transaction(function () use ($binding, $entryMode): EthereumSwapAttempt {
                $locked = TradeOpportunity::query()->lockForUpdate()->find($binding['trade_opportunity_id']);
                $existing = $locked?->ethereumSwapAttempt()->lockForUpdate()->first();
                if (! $locked || $locked->user_id !== $binding['user_id']
                    || $locked->chain !== Chain::Ethereum || $locked->entry_mode !== $entryMode
                    || $locked->status !== TradeOpportunityStatus::Executing
                    || ! $existing || ! $this->matchesBinding($existing, $binding)) {
                    throw new DomainException('This opportunity has a conflicting live execution reservation. Refresh the opportunity before retrying.');
                }

                return $existing;
            });
        }
    }

    /** @return array<string, int|string> */
    private function executionData(?int $attemptId, ?TradingEngineOpportunityDecision $decision): array
    {
        $data = ['executor' => 'live', 'stage' => 'reserved'];
        if ($attemptId !== null) {
            $data['ethereum_swap_attempt_id'] = $attemptId;
        }
        if ($decision !== null) {
            $data['source'] = EthereumOpportunityExecutionPolicy::ENGINE_AUTO_SOURCE;
            $data['engine_evaluation_id'] = $decision->evaluationId;
            $data['engine_opportunity_id'] = $decision->engineOpportunityId;
        }

        return $data;
    }

    /** @param array{trade_opportunity_id: int, user_id: int, connected_wallet_id: int, wallet_address: string, buy_token: string, sell_amount_wei: string, slippage_bps: int} $binding */
    private function matchesBinding(EthereumSwapAttempt $attempt, array $binding): bool
    {
        foreach ($binding as $field => $value) {
            $actual = $field === 'slippage_bps' ? (int) $attempt->{$field} : $attempt->{$field};
            if ($actual !== $value) {
                return false;
            }
        }

        return true;
    }
}
