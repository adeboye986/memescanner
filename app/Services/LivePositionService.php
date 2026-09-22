<?php

namespace App\Services;

use App\Chain;
use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Enums\TradeOpportunityStatus;
use App\Models\ConnectedWallet;
use App\Models\EthereumSwapAttempt;
use App\Models\LivePosition;
use App\Models\TradeOpportunity;
use DomainException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use LogicException;

class LivePositionService
{
    /** Backfill uses stored confirmation evidence only; no provider calls or execution-state updates. */
    public function backfill(EthereumSwapAttempt $candidate): LivePosition
    {
        return DB::transaction(function () use ($candidate): LivePosition {
            $opportunity = TradeOpportunity::query()->lockForUpdate()->find($candidate->trade_opportunity_id);
            $attempt = EthereumSwapAttempt::query()->lockForUpdate()->find($candidate->id);
            if (! $opportunity || ! $attempt || $attempt->trade_opportunity_id !== $opportunity->id) {
                throw new DomainException('Missing or conflicting authoritative opportunity linkage.');
            }

            return $this->ensureConfirmedEthereum($opportunity, $attempt);
        });
    }

    /** Caller must lock opportunity then attempt. RPC must have completed before these locks. */
    public function ensureConfirmedEthereum(TradeOpportunity $opportunity, EthereumSwapAttempt $attempt): LivePosition
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('LIVE entry creation requires the receipt transaction.');
        }
        $wallet = ConnectedWallet::query()->find($attempt->connected_wallet_id);
        try {
            $payload = $attempt->transaction_payload ?? [];
        } catch (DecryptException) {
            throw new DomainException('Retained Ethereum transaction evidence cannot be read.');
        }
        if (! is_array($payload)) {
            throw new DomainException('Retained Ethereum transaction evidence is malformed.');
        }
        if ($attempt->status !== 'confirmed' || $attempt->confirmed_at === null || $attempt->failed_at !== null
            || $attempt->failure_reason !== null || $opportunity->status !== TradeOpportunityStatus::Executed
            || $opportunity->executed_at === null || $opportunity->execution_mode !== ExecutionMode::Live
            || $opportunity->entry_mode !== EntryMode::Confirm || $opportunity->chain !== Chain::Ethereum
            || $attempt->trade_opportunity_id !== $opportunity->id || $attempt->user_id !== $opportunity->user_id
            || ! $wallet || $wallet->user_id !== $attempt->user_id || $wallet->chain !== Chain::Ethereum
            || ! $this->matchesAttemptId(data_get($opportunity->execution_data, 'ethereum_swap_attempt_id'), $attempt->id)
            || data_get($opportunity->execution_data, 'transaction_hash') !== $attempt->transaction_hash
            || ! $this->address($attempt->wallet_address) || ! $this->address($attempt->buy_token)
            || strtolower($opportunity->address) !== $attempt->buy_token
            || ! $this->address($payload['to'] ?? null)
            || ! is_string($payload['data'] ?? null) || preg_match('/^0x(?:[a-fA-F0-9]{2})*$/D', $payload['data']) !== 1
            || ($payload['from'] ?? null) !== $attempt->wallet_address
            || ($payload['value'] ?? null) !== $attempt->sell_amount_wei || ($payload['chainId'] ?? null) !== '1'
            || preg_match('/^0x[a-f0-9]{64}$/D', $attempt->transaction_hash ?? '') !== 1
            || ! $this->positiveInteger($attempt->sell_amount_wei)
            || ! $this->positiveInteger($attempt->block_number) || ! $this->positiveInteger($attempt->gas_used)
            || ! $this->unsignedInteger($attempt->effective_gas_price_wei) || ! $this->unsignedInteger($attempt->actual_network_fee_wei)
            || bcmul($attempt->gas_used, $attempt->effective_gas_price_wei, 0) !== $attempt->actual_network_fee_wei) {
            throw new DomainException('Insufficient or conflicting confirmed Ethereum entry evidence.');
        }

        $identity = ['user_id' => $attempt->user_id, 'chain' => Chain::Ethereum->value, 'network' => 'mainnet',
            'wallet_address' => $attempt->wallet_address, 'connected_wallet_id' => $attempt->connected_wallet_id,
            'trade_opportunity_id' => $opportunity->id, 'ethereum_swap_attempt_id' => $attempt->id,
            'token_address' => $attempt->buy_token, 'entry_transaction_hash' => $attempt->transaction_hash,
            'entry_block_number' => $attempt->block_number, 'entry_confirmed_at' => $attempt->confirmed_at->format('Y-m-d H:i:s')];
        $existing = LivePosition::query()->where('ethereum_swap_attempt_id', $attempt->id)
            ->orWhere('trade_opportunity_id', $opportunity->id)->lockForUpdate()->get();
        if ($existing->count() > 1) {
            throw new DomainException('Conflicting LIVE entry records.');
        }
        if ($position = $existing->first()) {
            foreach ($identity as $field => $value) {
                if ((string) $position->getRawOriginal($field) !== (string) $value) {
                    throw new DomainException('Existing LIVE entry evidence conflicts with its authoritative attempt.');
                }
            }

            return $position;
        }

        return LivePosition::query()->create([...$identity, 'accounting_status' => 'pending']);
    }

    private function matchesAttemptId(mixed $value, int|string $attemptId): bool
    {
        return (is_int($value) || is_string($value))
            && preg_match('/^[1-9][0-9]*$/D', (string) $value) === 1
            && (string) $value === (string) $attemptId;
    }

    private function address(mixed $value): bool
    {
        return is_string($value) && preg_match('/^0x[a-f0-9]{40}$/D', $value) === 1;
    }

    private function unsignedInteger(?string $value): bool
    {
        return preg_match('/^(0|[1-9][0-9]{0,77})$/D', $value ?? '') === 1;
    }

    private function positiveInteger(?string $value): bool
    {
        return $this->unsignedInteger($value) && $value !== '0';
    }
}
