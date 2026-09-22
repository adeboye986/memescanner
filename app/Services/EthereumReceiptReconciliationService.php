<?php

namespace App\Services;

use App\Chain;
use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Enums\TradeOpportunityStatus;
use App\Models\EthereumSwapAttempt;
use App\Models\TradeOpportunity;
use App\Models\TradeOpportunityEvent;
use Illuminate\Support\Facades\DB;

class EthereumReceiptReconciliationService
{
    /** RPC happens in the command, before this locking transaction.
     * @param  array{transaction_hash: string, succeeded: bool, block_number: string, gas_used: string, effective_gas_price_wei: string, actual_network_fee_wei: string}  $receipt
     */
    public function apply(EthereumSwapAttempt $candidate, array $receipt): ?string
    {
        return DB::transaction(function () use ($candidate, $receipt): ?string {
            $opportunity = $candidate->trade_opportunity_id ? TradeOpportunity::query()->lockForUpdate()->find($candidate->trade_opportunity_id) : null;
            $attempt = EthereumSwapAttempt::query()->lockForUpdate()->find($candidate->id);
            if (! $attempt || $attempt->status !== 'submitted' || ! $attempt->transaction_hash
                || $attempt->trade_opportunity_id !== $candidate->trade_opportunity_id
                || $attempt->transaction_hash !== $candidate->transaction_hash
                || strtolower($attempt->transaction_hash) !== $receipt['transaction_hash']) {
                return null;
            }
            if ($attempt->trade_opportunity_id !== null) {
                if (! $opportunity || $opportunity->user_id !== $attempt->user_id || $opportunity->chain !== Chain::Ethereum
                    || $opportunity->execution_mode !== ExecutionMode::Live || $opportunity->entry_mode !== EntryMode::Confirm
                    || strtolower($opportunity->address) !== $attempt->buy_token
                    || $opportunity->ethereumSwapAttempt()->value('id') !== $attempt->id
                    || (int) data_get($opportunity->execution_data, 'ethereum_swap_attempt_id') !== $attempt->id
                    || strtolower((string) data_get($attempt->transaction_payload, 'from')) !== $attempt->wallet_address) {
                    return null;
                }
                $alreadyExecuted = $opportunity->status === TradeOpportunityStatus::Executed && $receipt['succeeded']
                    && data_get($opportunity->execution_data, 'transaction_hash') === $attempt->transaction_hash
                    && $opportunity->executed_at !== null;
                if ($opportunity->status !== TradeOpportunityStatus::Executing && ! $alreadyExecuted) {
                    return null;
                }
            }
            $status = $receipt['succeeded'] ? 'confirmed' : 'failed';
            $attempt->update(['status' => $status, 'confirmed_at' => $receipt['succeeded'] ? now() : null,
                'failed_at' => $receipt['succeeded'] ? null : now(), 'failure_reason' => $receipt['succeeded'] ? null : 'Ethereum transaction reverted on-chain.',
                'block_number' => $receipt['block_number'], 'gas_used' => $receipt['gas_used'],
                'effective_gas_price_wei' => $receipt['effective_gas_price_wei'], 'actual_network_fee_wei' => $receipt['actual_network_fee_wei']]);
            if ($opportunity && $opportunity->status === TradeOpportunityStatus::Executing) {
                $opportunity->update(['status' => $receipt['succeeded'] ? TradeOpportunityStatus::Executed : TradeOpportunityStatus::Failed,
                    'executed_at' => $receipt['succeeded'] ? ($opportunity->executed_at ?? now()) : null,
                    'execution_data' => [...($opportunity->execution_data ?? []), 'stage' => $status,
                        'transaction_hash' => $attempt->transaction_hash, 'reason' => $receipt['succeeded'] ? 'ethereum_receipt_confirmed' : 'ethereum_receipt_reverted']]);
                TradeOpportunityEvent::query()->create(['trade_opportunity_id' => $opportunity->id, 'user_id' => $opportunity->user_id,
                    'action' => 'live_execution_'.$status, 'from_status' => 'executing', 'to_status' => $opportunity->status->value,
                    'metadata' => ['ethereum_swap_attempt_id' => $attempt->id, 'transaction_hash' => $attempt->transaction_hash]]);
            }

            return $status;
        });
    }
}
