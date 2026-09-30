<?php

namespace App\Services;

use App\Chain;
use App\Enums\ExecutionMode;
use App\Enums\TradeOpportunityStatus;
use App\Exceptions\SolanaPreparationException;
use App\Models\SolanaSwapAttempt;
use App\Models\TradeOpportunity;
use App\Models\TradeOpportunityEvent;
use Illuminate\Support\Facades\DB;

class SolanaReceiptReconciliationService
{
    public function __construct(
        private LivePositionService $positions,
        private SolanaOpportunityExecutionPolicy $executionPolicy,
        private SolanaPreparedAttemptIntegrity $integrity,
    ) {}

    public function failExpired(SolanaSwapAttempt $candidate): bool
    {
        return DB::transaction(function () use ($candidate): bool {
            $opportunity = $candidate->trade_opportunity_id
                ? TradeOpportunity::query()->lockForUpdate()->find($candidate->trade_opportunity_id)
                : null;
            $attempt = SolanaSwapAttempt::query()->lockForUpdate()->find($candidate->id);
            if (! $opportunity || ! $attempt || $attempt->status !== 'submitted'
                || $attempt->transaction_signature !== $candidate->transaction_signature) {
                return false;
            }
            try {
                $this->integrity->assertValid($opportunity, $attempt);
                $this->integrity->assertHumanHandoff($attempt);
            } catch (SolanaPreparationException) {
                return false;
            }
            if ($opportunity->status !== TradeOpportunityStatus::Executing
                || ! $this->executionPolicy->permitsSubmittedEvidence($opportunity)) {
                return false;
            }

            $attempt->update([
                'status' => 'failed', 'failed_at' => now(),
                'failure_reason' => 'Solana transaction was not found on-chain before its blockhash expired.',
            ]);
            $opportunity->update([
                'status' => TradeOpportunityStatus::Failed,
                'execution_data' => [...($opportunity->execution_data ?? []), 'stage' => 'failed',
                    'reason' => 'solana_transaction_not_found_before_expiry'],
            ]);
            TradeOpportunityEvent::query()->create([
                'trade_opportunity_id' => $opportunity->id, 'user_id' => $opportunity->user_id,
                'action' => 'live_execution_failed', 'from_status' => TradeOpportunityStatus::Executing->value,
                'to_status' => TradeOpportunityStatus::Failed->value,
                'metadata' => ['solana_swap_attempt_id' => $attempt->id,
                    'transaction_signature' => $attempt->transaction_signature],
            ]);

            return true;
        });
    }

    /** @param array{succeeded: bool, slot: int, network_fee_lamports: int, error: mixed, acquired_raw_amount?: string, token_decimals?: int} $receipt */
    public function apply(SolanaSwapAttempt $candidate, array $receipt): ?string
    {
        return DB::transaction(function () use ($candidate, $receipt): ?string {
            $opportunity = $candidate->trade_opportunity_id
                ? TradeOpportunity::query()->lockForUpdate()->find($candidate->trade_opportunity_id)
                : null;
            $attempt = SolanaSwapAttempt::query()->lockForUpdate()->find($candidate->id);
            if (! $opportunity || ! $attempt || $attempt->status !== 'submitted' || ! $attempt->transaction_signature
                || $attempt->trade_opportunity_id !== $candidate->trade_opportunity_id
                || $attempt->transaction_signature !== $candidate->transaction_signature) {
                return null;
            }

            try {
                $this->integrity->assertValid($opportunity, $attempt);
                $this->integrity->assertHumanHandoff($attempt);
            } catch (SolanaPreparationException) {
                return null;
            }
            if ($opportunity->user_id !== $attempt->user_id || $opportunity->chain !== Chain::Solana
                || $opportunity->execution_mode !== ExecutionMode::Live
                || ! $this->executionPolicy->permitsSubmittedEvidence($opportunity)
                || $opportunity->address !== $attempt->output_mint
                || (int) data_get($opportunity->execution_data, 'solana_swap_attempt_id') !== $attempt->id
                || $opportunity->status !== TradeOpportunityStatus::Executing) {
                return null;
            }

            $succeeded = $receipt['succeeded'];
            if ($succeeded && (! isset($receipt['acquired_raw_amount'], $receipt['token_decimals'])
                || preg_match('/^[1-9][0-9]{0,77}$/D', (string) $receipt['acquired_raw_amount']) !== 1
                || ! is_int($receipt['token_decimals']) || $receipt['token_decimals'] < 0 || $receipt['token_decimals'] > 30)) {
                return null;
            }

            $status = $succeeded ? 'confirmed' : 'failed';
            $attempt->update([
                'status' => $status,
                'confirmed_at' => $succeeded ? now() : null,
                'failed_at' => $succeeded ? null : now(),
                'failure_reason' => $succeeded ? null : 'Solana transaction failed on-chain.',
                'network_fee_lamports' => $receipt['network_fee_lamports'],
                'slot' => $receipt['slot'],
                'acquired_raw_amount' => $succeeded ? $receipt['acquired_raw_amount'] : null,
                'token_decimals' => $succeeded ? $receipt['token_decimals'] : null,
            ]);

            $opportunity->update([
                'status' => $succeeded ? TradeOpportunityStatus::Executed : TradeOpportunityStatus::Failed,
                'executed_at' => $succeeded ? ($opportunity->executed_at ?? now()) : null,
                'execution_data' => [...($opportunity->execution_data ?? []), 'stage' => $status,
                    'transaction_signature' => $attempt->transaction_signature,
                    'reason' => $succeeded ? 'solana_receipt_confirmed' : 'solana_receipt_failed'],
            ]);
            TradeOpportunityEvent::query()->create([
                'trade_opportunity_id' => $opportunity->id, 'user_id' => $opportunity->user_id,
                'action' => 'live_execution_'.$status, 'from_status' => TradeOpportunityStatus::Executing->value,
                'to_status' => $opportunity->status->value,
                'metadata' => ['solana_swap_attempt_id' => $attempt->id,
                    'transaction_signature' => $attempt->transaction_signature],
            ]);
            if ($succeeded) {
                $this->positions->ensureConfirmedSolana($opportunity, $attempt);
            }

            return $status;
        });
    }
}
