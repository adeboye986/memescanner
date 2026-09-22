<?php

namespace App\Services;

use App\Chain;
use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Enums\TradeOpportunityStatus;
use App\Exceptions\EthereumPreparationException;
use App\Models\ConnectedWallet;
use App\Models\EthereumSwapAttempt;
use App\Models\TradeOpportunity;
use App\Models\TradeOpportunityEvent;
use App\Models\User;
use App\Models\UserTradingPreference;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class EthereumOpportunityPreparationService
{
    public function __construct(
        private EthereumSwapPreparationService $preparation,
        private EthereumOpportunityRevalidationService $revalidation,
        private EthereumSwapInputRules $inputs,
        private ApplicationSettingsService $settings,
    ) {}

    public function prepare(TradeOpportunity $opportunity, User $user): EthereumSwapAttempt
    {
        $claim = DB::transaction(function () use ($opportunity, $user): EthereumSwapAttempt|EthereumPreparationException {
            $locked = TradeOpportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
            abort_unless($locked->user_id === $user->id, 404);
            $attempt = $locked->ethereumSwapAttempt()->lockForUpdate()->first();
            if (! $attempt || $attempt->user_id !== $user->id) {
                throw new EthereumPreparationException('An approved Ethereum reservation is required.');
            }
            if ($attempt->status === 'preparing') {
                throw new EthereumPreparationException('Preparation is already in progress. Refresh its status; do not start another preparation.', 409);
            }
            if (! in_array($attempt->status, ['reserved', 'prepared'], true) || $attempt->transaction_hash !== null) {
                throw new EthereumPreparationException('This reservation is no longer available for preparation.', 409);
            }
            try {
                $this->assertCurrent($locked, $attempt, $user->id);
            } catch (EthereumPreparationException $exception) {
                $this->releaseLocked($locked, $attempt, 'preparation_context_changed', TradeOpportunityStatus::Failed);

                return $exception;
            }
            if ($attempt->status === 'prepared') {
                if (! $attempt->expires_at || $attempt->expires_at->lte(now())) {
                    $this->releaseLocked($locked, $attempt, 'prepared_expired', TradeOpportunityStatus::Expired);

                    return new EthereumPreparationException('The prepared transaction has expired.', 409);
                }

                return $attempt;
            }
            if ($attempt->updated_at->lte(now()->subMinutes(5))) {
                $this->releaseLocked($locked, $attempt, 'reservation_abandoned', TradeOpportunityStatus::PendingConfirmation);

                return new EthereumPreparationException('The reservation expired. Explicit approval is required again.', 409);
            }
            $attempt->update(['status' => 'preparing', 'preparation_token' => (string) Str::uuid(), 'preparation_expires_at' => now()->addMinutes(3)]);
            $locked->update(['execution_data' => [...($locked->execution_data ?? []), 'stage' => 'preparing']]);

            return $attempt;
        });
        if ($claim instanceof EthereumPreparationException) {
            throw $claim;
        }
        if ($claim->status === 'prepared') {
            return $claim;
        }

        $binding = $this->binding($claim);
        $audit = null;
        try {
            $audit = $this->revalidation->check($opportunity->fresh());
            if (($audit['passed'] ?? false) !== true) {
                $unsafe = ($audit['failure_class'] ?? 'unsafe') === 'unsafe';
                $this->fail($claim, $unsafe ? 'qualification_failed' : 'qualification_unavailable', $unsafe, $audit);
                throw new EthereumPreparationException($unsafe
                    ? 'Fresh Ethereum market/security validation did not pass. A newly qualified opportunity is required.'
                    : 'Fresh Ethereum validation is unavailable. Refresh the opportunity before explicitly approving again.', $unsafe ? 422 : 503);
            }
            $wallet = ConnectedWallet::query()->find($claim->connected_wallet_id);
            $this->preparation->assertWallet($wallet, $user->id, $claim->wallet_address);
            $prepared = $this->preparation->quote($wallet, $user->id, $claim->wallet_address, $this->input($claim));

            return DB::transaction(function () use ($claim, $user, $prepared, $binding, $audit): EthereumSwapAttempt {
                $locked = TradeOpportunity::query()->lockForUpdate()->findOrFail($claim->trade_opportunity_id);
                $attempt = $locked->ethereumSwapAttempt()->lockForUpdate()->first();
                if (! $attempt || $attempt->status !== 'preparing' || $attempt->preparation_token !== $claim->preparation_token
                    || ! $attempt->preparation_expires_at || $attempt->preparation_expires_at->lte(now())
                    || $this->binding($attempt) !== $binding || $attempt->transaction_hash !== null) {
                    throw new EthereumPreparationException('Preparation was superseded or expired. No transaction was published.', 409);
                }
                $this->assertCurrent($locked, $attempt, $user->id);
                if ($locked->scanner !== ($audit['scanner'] ?? null) || $prepared['expires_at']->lte(now()) || now()->diffInSeconds($audit['checked_at'], true) > 60) {
                    throw new EthereumPreparationException('Preparation or validation became stale. No transaction was published.', 409);
                }
                $attempt->update([...$prepared, 'status' => 'prepared', 'preparation_token' => null, 'preparation_expires_at' => null, 'revalidation_data' => $audit]);
                $locked->update(['execution_data' => [...($locked->execution_data ?? []), 'stage' => 'prepared']]);
                $this->event($locked, 'live_execution_prepared', $locked->status, 'prepared');

                return $attempt;
            });
        } catch (Throwable $exception) {
            $this->fail($claim, 'preparation_failed', false, $audit);
            if ($exception instanceof EthereumPreparationException) {
                throw $exception;
            }
            if ($exception instanceof QueryException) {
                Log::warning('Ethereum preparation database operation failed.');
            } else {
                report($exception);
            }
            throw new EthereumPreparationException('Ethereum preparation is unavailable. No transaction was published.', 503);
        }
    }

    /** @return array{attempt: EthereumSwapAttempt, signing_claim_token: string} */
    public function claimForSigning(TradeOpportunity $opportunity, User $user): array
    {
        return DB::transaction(function () use ($opportunity, $user): array {
            $locked = TradeOpportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
            abort_unless($locked->user_id === $user->id, 404);
            $attempt = $locked->ethereumSwapAttempt()->lockForUpdate()->first();
            if (! $attempt || $attempt->status !== 'prepared' || $attempt->transaction_hash !== null
                || $attempt->signing_requested_at !== null || $attempt->signing_armed_at !== null) {
                throw new EthereumPreparationException('Signing handoff is unavailable or unresolved. Refresh its status; do not send again.', 409);
            }
            $this->assertCurrent($locked, $attempt, $user->id);
            if (! $attempt->expires_at || $attempt->expires_at->lte(now())) {
                throw new EthereumPreparationException('The prepared transaction expired.', 409);
            }
            $token = bin2hex(random_bytes(32));
            $attempt->update(['signing_requested_at' => now(), 'signing_claim_hash' => hash('sha256', $token)]);

            return ['attempt' => $attempt, 'signing_claim_token' => $token];
        });
    }

    /** Only the exact active claim can release before arm or report definitive rejection after arm. */
    public function transitionSigning(TradeOpportunity $opportunity, User $user, string $token, string $action): EthereumSwapAttempt
    {
        $result = DB::transaction(function () use ($opportunity, $user, $token, $action): EthereumSwapAttempt|EthereumPreparationException {
            $locked = TradeOpportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
            abort_unless($locked->user_id === $user->id, 404);
            $attempt = $locked->ethereumSwapAttempt()->lockForUpdate()->first();
            if (! $attempt || $attempt->user_id !== $user->id || $attempt->transaction_hash !== null
                || ! in_array($attempt->status, ['prepared', 'expired'], true)
                || ! $attempt->signing_claim_hash || ! hash_equals($attempt->signing_claim_hash, hash('sha256', $token))
                || $locked->chain !== Chain::Ethereum || $locked->execution_mode !== ExecutionMode::Live
                || $locked->entry_mode !== EntryMode::Confirm || strtolower($locked->address) !== $attempt->buy_token
                || (int) data_get($locked->execution_data, 'ethereum_swap_attempt_id') !== $attempt->id
                || ! in_array($locked->status, [TradeOpportunityStatus::Executing, TradeOpportunityStatus::Expired], true)) {
                throw new EthereumPreparationException('The signing claim is unavailable.', 409);
            }
            if ($action === 'release') {
                if ($attempt->signing_armed_at !== null) {
                    throw new EthereumPreparationException('An armed signing request cannot be released. Its outcome is unresolved.', 409);
                }
                $attempt->update(['signing_requested_at' => null, 'signing_claim_hash' => null]);
                if (! $attempt->expires_at || $attempt->expires_at->lte(now())) {
                    $this->releaseLocked($locked, $attempt, 'prepared_expired', TradeOpportunityStatus::Expired);
                }
            } elseif ($action === 'arm') {
                if ($attempt->signing_armed_at !== null) {
                    throw new EthereumPreparationException('Signing is already armed. Do not send again.', 409);
                }
                $this->assertCurrent($locked, $attempt, $user->id);
                if ($attempt->status !== 'prepared' || ! $attempt->expires_at || $attempt->expires_at->lte(now())) {
                    $this->releaseLocked($locked, $attempt, 'prepared_expired', TradeOpportunityStatus::Expired);

                    return new EthereumPreparationException('The prepared transaction expired before signing was armed.', 409);
                }
                $attempt->update(['signing_armed_at' => now()]);
            } elseif ($action === 'rejected') {
                if ($attempt->signing_armed_at === null) {
                    throw new EthereumPreparationException('The signing request was not armed.', 409);
                }
                $attempt->update(['signing_claim_hash' => null]);
                $this->releaseLocked($locked, $attempt, 'wallet_cancelled', TradeOpportunityStatus::Ignored);
            } else {
                throw new EthereumPreparationException('Unknown signing action.', 422);
            }

            return $attempt;
        });
        if ($result instanceof EthereumPreparationException) {
            throw $result;
        }

        return $result;
    }

    /** Recheck live controls and immutable identity under locks, including after HTTP. */
    private function assertCurrent(TradeOpportunity $opportunity, EthereumSwapAttempt $attempt, int $userId): void
    {
        if ($opportunity->user_id !== $userId || $attempt->user_id !== $userId
            || $opportunity->chain !== Chain::Ethereum || $opportunity->entry_mode !== EntryMode::Confirm
            || $opportunity->execution_mode !== ExecutionMode::Live || $opportunity->status !== TradeOpportunityStatus::Executing
            || strtolower($opportunity->address) !== $attempt->buy_token) {
            throw new EthereumPreparationException('The live Ethereum opportunity is no longer eligible for preparation.');
        }
        $preference = UserTradingPreference::query()->where('user_id', $userId)->lockForUpdate()->first();
        if (! $preference || $preference->execution_mode !== ExecutionMode::Live || $preference->entry_mode !== EntryMode::Confirm
            || ! $preference->trading_enabled || $this->settings->get('risk.kill_switch')) {
            throw new EthereumPreparationException('Live confirmation is disabled or its trading controls have changed.');
        }
        $wallet = ConnectedWallet::query()->lockForUpdate()->find($attempt->connected_wallet_id);
        $this->preparation->assertWallet($wallet, $userId, $attempt->wallet_address);
        try {
            Validator::make($this->input($attempt), $this->inputs->rules())->validate();
        } catch (ValidationException) {
            throw new EthereumPreparationException('The reserved trade no longer meets current Ethereum risk limits.');
        }
    }

    /** @param array<string, mixed>|null $audit */
    private function fail(EthereumSwapAttempt $claim, string $reason, bool $terminal, ?array $audit): void
    {
        DB::transaction(function () use ($claim, $reason, $terminal, $audit): void {
            $opportunity = TradeOpportunity::query()->lockForUpdate()->find($claim->trade_opportunity_id);
            $attempt = $opportunity?->ethereumSwapAttempt()->lockForUpdate()->first();
            if (! $attempt || $attempt->status !== 'preparing' || $attempt->preparation_token !== $claim->preparation_token) {
                return;
            }
            $attempt->update(['revalidation_data' => $audit]);
            $this->releaseLocked($opportunity, $attempt, $reason, $terminal ? TradeOpportunityStatus::Failed : TradeOpportunityStatus::PendingConfirmation);
        });
    }

    /** Caller locks opportunity before attempt. Never clear a published payload. */
    private function releaseLocked(TradeOpportunity $opportunity, EthereumSwapAttempt $attempt, string $reason, TradeOpportunityStatus $target): void
    {
        if ($attempt->user_id !== $opportunity->user_id || $opportunity->chain !== Chain::Ethereum
            || $attempt->transaction_hash !== null || ! in_array($attempt->status, ['reserved', 'preparing', 'prepared', 'expired', 'cancelled'], true)) {
            return;
        }
        if ($target === TradeOpportunityStatus::PendingConfirmation && ! app(EthereumOpportunityFreshness::class)->isFresh($opportunity)) {
            $target = TradeOpportunityStatus::Expired;
        }
        $published = $attempt->transaction_payload !== null;
        if ($published) {
            $target = $reason === 'wallet_cancelled' ? TradeOpportunityStatus::Ignored : TradeOpportunityStatus::Expired;
        }
        $attempt->update([
            'status' => $published ? ($reason === 'wallet_cancelled' ? 'cancelled' : 'expired') : match ($target) {
                TradeOpportunityStatus::Failed => 'failed', TradeOpportunityStatus::Expired => 'expired', default => 'released'
            },
            'preparation_token' => null, 'preparation_expires_at' => null, 'failure_reason' => $reason,
        ]);
        if ($opportunity->status === TradeOpportunityStatus::Executing) {
            $opportunity->update(['status' => $target, 'execution_data' => [...($opportunity->execution_data ?? []), 'stage' => $attempt->status, 'reason' => $reason]]);
            $this->event($opportunity, 'live_execution_released', TradeOpportunityStatus::Executing, $reason);
        }
    }

    public function cancel(EthereumSwapAttempt $original, User $user): EthereumSwapAttempt
    {
        return DB::transaction(function () use ($original, $user): EthereumSwapAttempt {
            $opportunity = TradeOpportunity::query()->lockForUpdate()->findOrFail($original->trade_opportunity_id);
            abort_unless($opportunity->user_id === $user->id, 404);
            $attempt = $opportunity->ethereumSwapAttempt()->lockForUpdate()->firstOrFail();
            if ($attempt->status !== 'prepared' || $attempt->transaction_hash !== null || $attempt->signing_requested_at !== null || $attempt->signing_armed_at !== null) {
                throw new EthereumPreparationException('This Ethereum swap order can no longer be cancelled.');
            }
            $reason = $attempt->expires_at->lte(now()) ? 'prepared_expired' : 'wallet_cancelled';
            $this->releaseLocked($opportunity, $attempt, $reason, TradeOpportunityStatus::Ignored);

            return $attempt;
        });
    }

    public function cleanup(): void
    {
        EthereumSwapAttempt::query()->whereNotNull('trade_opportunity_id')
            ->whereIn('status', ['reserved', 'preparing', 'prepared', 'expired', 'cancelled'])->orderBy('id')
            ->chunkById(100, function ($attempts): void {
                foreach ($attempts as $candidate) {
                    DB::transaction(function () use ($candidate): void {
                        $opportunity = TradeOpportunity::query()->lockForUpdate()->find($candidate->trade_opportunity_id);
                        $attempt = $opportunity?->ethereumSwapAttempt()->lockForUpdate()->first();
                        if (! $attempt || $attempt->transaction_hash !== null) {
                            return;
                        }
                        $reason = match (true) {
                            $attempt->status === 'reserved' && $attempt->updated_at->lte(now()->subMinutes(5)) => 'reservation_abandoned',
                            $attempt->status === 'preparing' && (! $attempt->preparation_expires_at || $attempt->preparation_expires_at->lte(now())) => 'preparation_abandoned',
                            $attempt->status === 'prepared' && (! $attempt->expires_at || $attempt->expires_at->lte(now())) => 'prepared_expired',
                            $attempt->status === 'expired' && $opportunity->status === TradeOpportunityStatus::Executing => 'prepared_expired',
                            $attempt->status === 'cancelled' && $opportunity->status === TradeOpportunityStatus::Executing => 'wallet_cancelled',
                            default => null,
                        };
                        if ($reason) {
                            $this->releaseLocked($opportunity, $attempt, $reason, TradeOpportunityStatus::PendingConfirmation);
                        }
                    });
                }
            });
        TradeOpportunity::query()->where('chain', Chain::Ethereum->value)->where('status', TradeOpportunityStatus::Executing)
            ->whereDoesntHave('ethereumSwapAttempt')->orderBy('id')->chunkById(100, function ($opportunities): void {
                foreach ($opportunities as $candidate) {
                    DB::transaction(function () use ($candidate): void {
                        $opportunity = TradeOpportunity::query()->lockForUpdate()->find($candidate->id);
                        if ($opportunity && $opportunity->status === TradeOpportunityStatus::Executing
                            && in_array(data_get($opportunity->execution_data, 'stage'), ['reserved', 'preparing', 'prepared'], true)
                            && ! $opportunity->ethereumSwapAttempt()->exists()) {
                            $opportunity->update(['status' => TradeOpportunityStatus::Failed, 'execution_data' => [...$opportunity->execution_data, 'reason' => 'reservation_missing']]);
                            $this->event($opportunity, 'live_execution_released', TradeOpportunityStatus::Executing, 'reservation_missing');
                        }
                    });
                }
            });
    }

    /** @return array{buy_token: string, sell_amount_wei: string, slippage_bps: int} */
    private function input(EthereumSwapAttempt $attempt): array
    {
        return ['buy_token' => $attempt->buy_token, 'sell_amount_wei' => $attempt->sell_amount_wei, 'slippage_bps' => (int) $attempt->slippage_bps];
    }

    /** @return array<string, mixed> */
    private function binding(EthereumSwapAttempt $attempt): array
    {
        return $attempt->only(['id', 'trade_opportunity_id', 'user_id', 'connected_wallet_id', 'wallet_address', 'buy_token', 'sell_amount_wei', 'slippage_bps']);
    }

    private function event(TradeOpportunity $opportunity, string $action, TradeOpportunityStatus $from, string $reason): void
    {
        TradeOpportunityEvent::query()->create(['trade_opportunity_id' => $opportunity->id, 'user_id' => $opportunity->user_id,
            'action' => $action, 'from_status' => $from->value, 'to_status' => $opportunity->status->value, 'metadata' => ['reason' => $reason]]);
    }
}
