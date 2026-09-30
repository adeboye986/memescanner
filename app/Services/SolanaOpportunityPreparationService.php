<?php

namespace App\Services;

use App\Chain;
use App\Enums\ExecutionMode;
use App\Enums\TradeOpportunityStatus;
use App\Exceptions\SolanaPreparationException;
use App\Models\ConnectedWallet;
use App\Models\SolanaSwapAttempt;
use App\Models\TradeOpportunity;
use App\Models\TradeOpportunityEvent;
use App\Models\User;
use App\Models\UserTradingPreference;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SolanaOpportunityPreparationService
{
    public function __construct(
        private ApplicationSettingsService $settings,
        private JupiterSwapOrderService $orders,
        private SolanaService $solana,
        private SolanaOpportunityExecutionPolicy $executionPolicy,
        private SolanaPreparedAttemptIntegrity $integrity,
        private SolanaOpportunityFreshness $freshness,
    ) {}

    public function prepare(TradeOpportunity $opportunity, User $user): SolanaSwapAttempt
    {
        $claim = DB::transaction(function () use ($opportunity, $user): SolanaSwapAttempt|SolanaPreparationException {
            $locked = TradeOpportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
            abort_unless($locked->user_id === $user->id, 404);
            $attempt = $locked->solanaSwapAttempt()->lockForUpdate()->first();
            if (! $attempt || ! in_array($attempt->status, ['reserved', 'prepared'], true)
                || $attempt->transaction_signature !== null) {
                throw new SolanaPreparationException('This Solana reservation is no longer available for preparation.', 409);
            }
            $this->assertCurrent($locked, $attempt, $user->id);
            if ($attempt->status === 'prepared') {
                $this->integrity->assertValid($locked, $attempt);
                if (! $attempt->expires_at || $attempt->expires_at->lte(now())) {
                    $this->expireLocked($locked, $attempt, 'prepared_expired');

                    return new SolanaPreparationException('The prepared Solana transaction expired.', 409);
                }

                return $attempt;
            }

            $attempt->update([
                'status' => 'preparing',
                'preparation_token' => (string) Str::uuid(),
                'preparation_expires_at' => now()->addMinutes(3),
            ]);
            $locked->update(['execution_data' => [...($locked->execution_data ?? []), 'stage' => 'preparing']]);

            return $attempt;
        });
        if ($claim instanceof SolanaPreparationException) {
            throw $claim;
        }
        if ($claim->status === 'prepared') {
            return $claim;
        }

        $binding = $this->reservationBinding($claim);
        try {
            $wallet = ConnectedWallet::query()->find($claim->connected_wallet_id);
            if (! $wallet || $wallet->user_id !== $user->id || $wallet->chain !== Chain::Solana
                || ! $wallet->isVerified() || $wallet->address !== $claim->wallet_address) {
                throw new SolanaPreparationException('The reserved Solana wallet is no longer active.', 409);
            }
            $balance = $this->solana->getBalanceLamports($wallet->address);
            if ((int) $claim->input_amount_lamports > $balance) {
                throw new SolanaPreparationException('The connected wallet lacks the reserved SOL amount.', 422);
            }
            $order = $this->orders->prepare(
                $wallet->address,
                $claim->output_mint,
                (string) $claim->input_amount_lamports,
                (int) $claim->slippage_bps,
            );

            return DB::transaction(function () use ($claim, $user, $order, $binding): SolanaSwapAttempt {
                $locked = TradeOpportunity::query()->lockForUpdate()->findOrFail($claim->trade_opportunity_id);
                $attempt = $locked->solanaSwapAttempt()->lockForUpdate()->first();
                if (! $attempt || $attempt->status !== 'preparing'
                    || $attempt->preparation_token !== $claim->preparation_token
                    || ! $attempt->preparation_expires_at || $attempt->preparation_expires_at->lte(now())
                    || $this->reservationBinding($attempt) !== $binding || $attempt->transaction_signature !== null) {
                    throw new SolanaPreparationException('Solana preparation was superseded or expired. No transaction was published.', 409);
                }
                $this->assertCurrent($locked, $attempt, $user->id);
                $this->freshness->assertFresh($locked);
                $attempt->update([
                    'request_id' => $order['request_id'],
                    'message_hash' => $order['validation']['message_hash'],
                    'recent_blockhash' => $order['validation']['recent_blockhash'],
                    'prepared_transaction' => $order['transaction'],
                    'expires_at' => now()->addMinutes(2),
                    'status' => 'prepared',
                    'preparation_token' => null,
                    'preparation_expires_at' => null,
                    'failure_reason' => null,
                ]);
                $attempt->refresh();
                $this->integrity->seal($locked, $attempt);
                $locked->update(['execution_data' => [...($locked->execution_data ?? []), 'stage' => 'prepared']]);
                $this->event($locked, 'live_execution_prepared', 'prepared');

                return $attempt;
            });
        } catch (Throwable $exception) {
            $this->releasePreparationLease($claim);
            if ($exception instanceof SolanaPreparationException) {
                throw $exception;
            }
            if ($exception instanceof QueryException) {
                Log::warning('Solana opportunity preparation database operation failed.');
            } else {
                report($exception);
            }

            throw new SolanaPreparationException('Solana preparation is unavailable. No transaction was published.', 503);
        }
    }

    /** @return array{attempt: SolanaSwapAttempt, signing_claim_token: string} */
    public function claimForSigning(TradeOpportunity $opportunity, User $user): array
    {
        return DB::transaction(function () use ($opportunity, $user): array {
            $locked = TradeOpportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
            abort_unless($locked->user_id === $user->id, 404);
            $attempt = $locked->solanaSwapAttempt()->lockForUpdate()->first();
            if (! $attempt || $attempt->status !== 'prepared' || $attempt->transaction_signature !== null
                || $attempt->signing_requested_at !== null || $attempt->signing_armed_at !== null) {
                throw new SolanaPreparationException('Solana signing handoff is unavailable or unresolved. Do not send again.', 409);
            }
            $this->assertCurrent($locked, $attempt, $user->id);
            $this->integrity->assertValid($locked, $attempt);
            if (! $attempt->expires_at || $attempt->expires_at->lte(now())) {
                $this->expireLocked($locked, $attempt, 'prepared_expired');
                throw new SolanaPreparationException('The prepared Solana transaction expired.', 409);
            }

            $token = bin2hex(random_bytes(32));
            $attempt->update(['signing_requested_at' => now(), 'signing_claim_hash' => hash('sha256', $token)]);

            return ['attempt' => $attempt, 'signing_claim_token' => $token];
        });
    }

    public function transitionSigning(TradeOpportunity $opportunity, User $user, string $token, string $action): SolanaSwapAttempt
    {
        return DB::transaction(function () use ($opportunity, $user, $token, $action): SolanaSwapAttempt {
            $locked = TradeOpportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
            abort_unless($locked->user_id === $user->id, 404);
            $attempt = $locked->solanaSwapAttempt()->lockForUpdate()->first();
            if (! $attempt || $attempt->user_id !== $user->id || $attempt->transaction_signature !== null
                || ! in_array($attempt->status, ['prepared', 'expired'], true)
                || ! $attempt->signing_claim_hash
                || ! hash_equals($attempt->signing_claim_hash, hash('sha256', $token))) {
                throw new SolanaPreparationException('The Solana signing claim is unavailable.', 409);
            }
            $this->integrity->assertValid($locked, $attempt);

            if ($action === 'release') {
                if ($attempt->signing_armed_at !== null) {
                    throw new SolanaPreparationException('An armed Solana signing request cannot be released.', 409);
                }
                $attempt->update(['signing_requested_at' => null, 'signing_claim_hash' => null]);
            } elseif ($action === 'arm') {
                if ($attempt->signing_armed_at !== null) {
                    throw new SolanaPreparationException('Solana signing is already armed. Do not send again.', 409);
                }
                $this->assertCurrent($locked, $attempt, $user->id);
                if ($attempt->status !== 'prepared' || ! $attempt->expires_at || $attempt->expires_at->lte(now())) {
                    $this->expireLocked($locked, $attempt, 'prepared_expired');
                    throw new SolanaPreparationException('The prepared Solana transaction expired before signing.', 409);
                }
                $attempt->update(['signing_armed_at' => now()]);
            } elseif ($action === 'rejected') {
                if ($attempt->signing_armed_at === null) {
                    throw new SolanaPreparationException('The Solana signing request was not armed.', 409);
                }
                $attempt->update(['status' => 'cancelled', 'signing_claim_hash' => null, 'failure_reason' => 'wallet_cancelled']);
                $locked->update([
                    'status' => TradeOpportunityStatus::Ignored,
                    'execution_data' => [...($locked->execution_data ?? []), 'stage' => 'cancelled', 'reason' => 'wallet_cancelled'],
                ]);
                $this->event($locked, 'live_execution_released', 'wallet_cancelled');
            } else {
                throw new SolanaPreparationException('Unknown signing action.', 422);
            }

            return $attempt;
        });
    }

    public function cleanup(): int
    {
        $expired = 0;

        SolanaSwapAttempt::query()
            ->whereNotNull('trade_opportunity_id')
            ->whereIn('status', ['reserved', 'preparing', 'prepared', 'expired'])
            ->orderBy('id')
            ->chunkById(100, function ($attempts) use (&$expired): void {
                foreach ($attempts as $candidate) {
                    DB::transaction(function () use ($candidate, &$expired): void {
                        $opportunity = TradeOpportunity::query()->lockForUpdate()->find($candidate->trade_opportunity_id);
                        $attempt = $opportunity?->solanaSwapAttempt()->lockForUpdate()->first();
                        if (! $opportunity || ! $attempt || $attempt->transaction_signature !== null) {
                            return;
                        }

                        $reason = match (true) {
                            $attempt->status === 'reserved' && $attempt->updated_at->lte(now()->subMinutes(5)) => 'reservation_abandoned',
                            $attempt->status === 'preparing'
                                && (! $attempt->preparation_expires_at || $attempt->preparation_expires_at->lte(now())) => 'preparation_abandoned',
                            $attempt->status === 'prepared'
                                && (! $attempt->expires_at || $attempt->expires_at->lte(now())) => 'prepared_expired',
                            $attempt->status === 'expired'
                                && $opportunity->status === TradeOpportunityStatus::Executing => 'prepared_expired',
                            default => null,
                        };
                        if ($reason === null) {
                            return;
                        }

                        $this->expireLocked($opportunity, $attempt, $reason);
                        $expired++;
                    });
                }
            });

        return $expired;
    }

    private function assertCurrent(TradeOpportunity $opportunity, SolanaSwapAttempt $attempt, int $userId): void
    {
        if ($opportunity->user_id !== $userId || $attempt->user_id !== $userId
            || $opportunity->chain !== Chain::Solana || $opportunity->execution_mode !== ExecutionMode::Live
            || $opportunity->status !== TradeOpportunityStatus::Executing
            || ! $this->executionPolicy->permitsConfirmFirstFlow($opportunity)
            || $opportunity->address !== $attempt->output_mint
            || (int) data_get($opportunity->execution_data, 'solana_swap_attempt_id') !== $attempt->id) {
            throw new SolanaPreparationException('The Solana LIVE opportunity is no longer eligible for preparation.', 409);
        }

        $preference = UserTradingPreference::query()->where('user_id', $userId)->lockForUpdate()->first();
        if (! $preference || $preference->execution_mode !== ExecutionMode::Live
            || $preference->entry_mode->value !== 'auto' || ! $preference->trading_enabled
            || $this->settings->get('risk.kill_switch')) {
            throw new SolanaPreparationException('Solana LIVE confirmation is disabled or its controls changed.', 409);
        }

        $wallet = ConnectedWallet::query()->lockForUpdate()->find($attempt->connected_wallet_id);
        if (! $wallet || $wallet->user_id !== $userId || $wallet->chain !== Chain::Solana
            || ! $wallet->isVerified() || $wallet->address !== $attempt->wallet_address) {
            throw new SolanaPreparationException('The reserved Solana wallet is no longer active.', 409);
        }
    }

    private function releasePreparationLease(SolanaSwapAttempt $candidate): void
    {
        DB::transaction(function () use ($candidate): void {
            $opportunity = TradeOpportunity::query()->lockForUpdate()->find($candidate->trade_opportunity_id);
            $attempt = $opportunity?->solanaSwapAttempt()->lockForUpdate()->first();
            if (! $attempt || $attempt->status !== 'preparing'
                || $attempt->preparation_token !== $candidate->preparation_token) {
                return;
            }
            $attempt->update(['status' => 'reserved', 'preparation_token' => null, 'preparation_expires_at' => null]);
            $opportunity->update(['execution_data' => [...($opportunity->execution_data ?? []), 'stage' => 'reserved']]);
        });
    }

    private function expireLocked(TradeOpportunity $opportunity, SolanaSwapAttempt $attempt, string $reason): void
    {
        $attempt->update([
            'status' => 'expired', 'preparation_token' => null, 'preparation_expires_at' => null,
            'failure_reason' => $reason,
        ]);
        if ($opportunity->status === TradeOpportunityStatus::Executing) {
            $opportunity->update([
                'status' => TradeOpportunityStatus::Expired,
                'execution_data' => [...($opportunity->execution_data ?? []), 'stage' => 'expired', 'reason' => $reason],
            ]);
            $this->event($opportunity, 'live_execution_released', $reason);
        }
    }

    /** @return array<string, int|string|null> */
    private function reservationBinding(SolanaSwapAttempt $attempt): array
    {
        return $attempt->only([
            'id', 'trade_opportunity_id', 'user_id', 'connected_wallet_id', 'wallet_address', 'input_mint',
            'output_mint', 'input_amount_lamports', 'slippage_bps', 'network', 'preparation_origin',
        ]);
    }

    private function event(TradeOpportunity $opportunity, string $action, string $reason): void
    {
        TradeOpportunityEvent::query()->create([
            'trade_opportunity_id' => $opportunity->id, 'user_id' => $opportunity->user_id,
            'action' => $action, 'from_status' => TradeOpportunityStatus::Executing->value,
            'to_status' => $opportunity->status->value, 'metadata' => ['reason' => $reason],
        ]);
    }
}
