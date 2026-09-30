<?php

namespace App\Http\Controllers;

use App\Chain;
use App\Enums\ExecutionMode;
use App\Enums\TradeOpportunityStatus;
use App\Exceptions\SolanaPreparationException;
use App\Models\SolanaSwapAttempt;
use App\Models\TradeOpportunity;
use App\Services\JupiterSwapExecutionService;
use App\Services\SolanaOpportunityExecutionPolicy;
use App\Services\SolanaOpportunityPreparationService;
use App\Services\SolanaPreparedAttemptIntegrity;
use App\Services\SolanaService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SolanaOpportunityController extends Controller
{
    public function prepare(Request $request, TradeOpportunity $opportunity, SolanaOpportunityPreparationService $preparation): JsonResponse
    {
        $this->owner($request, $opportunity);
        try {
            $attempt = $preparation->prepare($opportunity, $request->user());
        } catch (SolanaPreparationException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->httpStatus);
        } catch (QueryException) {
            Log::warning('Solana opportunity preparation database operation failed.');

            return response()->json(['message' => 'Preparation could not be saved. Refresh before retrying.'], 503);
        }

        return response()->json(['order' => [
            'attempt_id' => $attempt->id,
            'expires_at' => $attempt->expires_at?->toIso8601String(),
        ]]);
    }

    public function confirm(Request $request, TradeOpportunity $opportunity, SolanaOpportunityPreparationService $preparation): JsonResponse
    {
        $this->owner($request, $opportunity);
        try {
            $claim = $preparation->claimForSigning($opportunity, $request->user());
            $attempt = $claim['attempt'];

            return response()->json(['order' => [
                'attempt_id' => $attempt->id,
                'transaction' => $attempt->prepared_transaction,
                'signing_claim_token' => $claim['signing_claim_token'],
                'valid_for_ms' => max(0, min(10_000, (int) now()->diffInMilliseconds($attempt->expires_at, false))),
            ]])->header('Cache-Control', 'no-store');
        } catch (SolanaPreparationException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->httpStatus);
        } catch (QueryException) {
            return $this->databaseFailure();
        }
    }

    public function transition(
        Request $request,
        TradeOpportunity $opportunity,
        string $action,
        SolanaOpportunityPreparationService $preparation,
    ): JsonResponse {
        $this->owner($request, $opportunity);
        $input = $request->validate([
            'signing_claim_token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'rejection_code' => $action === 'rejected' ? ['required', 'integer', 'in:4001'] : ['prohibited'],
        ]);

        try {
            $attempt = $preparation->transitionSigning(
                $opportunity,
                $request->user(),
                $input['signing_claim_token'],
                $action,
            );

            return response()->json([
                'status' => $attempt->status,
                'armed' => $attempt->signing_armed_at !== null,
            ])->header('Cache-Control', 'no-store');
        } catch (SolanaPreparationException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->httpStatus);
        } catch (QueryException) {
            return $this->databaseFailure();
        }
    }

    public function submitted(
        Request $request,
        TradeOpportunity $opportunity,
        SolanaService $solana,
        JupiterSwapExecutionService $executions,
        SolanaOpportunityExecutionPolicy $executionPolicy,
        SolanaPreparedAttemptIntegrity $integrity,
    ): JsonResponse {
        $this->owner($request, $opportunity);
        $input = $request->validate([
            'attempt_id' => ['required', 'integer'],
            'transaction_signature' => ['required', 'string', 'regex:/^[1-9A-HJ-NP-Za-km-z]{80,90}$/D'],
        ]);
        $signature = $input['transaction_signature'];
        $attempt = SolanaSwapAttempt::query()
            ->whereKey($input['attempt_id'])
            ->where('trade_opportunity_id', $opportunity->id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        try {
            $this->assertSubmissionAvailable($attempt, $signature);
            $integrity->assertValid($opportunity, $attempt);
            $integrity->assertHumanHandoff($attempt);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        if ($attempt->transaction_signature !== null) {
            return $this->submissionResponse($attempt);
        }

        try {
            $chainTransaction = $solana->getTransactionBase64($signature);
            if ($chainTransaction === null) {
                throw new RuntimeException('not_yet_available');
            }
            $validation = $executions->validateSigned($attempt, $chainTransaction, $attempt->wallet_address);
            if (! hash_equals($signature, $validation['transaction_signature'])) {
                throw new SolanaPreparationException('The reported Solana transaction does not match the prepared swap.', 422);
            }
        } catch (SolanaPreparationException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->httpStatus);
        } catch (RuntimeException) {
            return response()->json([
                'message' => 'The broadcast Solana transaction could not be verified. Retry the same signature; do not send another transaction.',
                'retryable' => true, 'attempt_id' => $attempt->id, 'transaction_signature' => $signature,
            ], 503);
        }

        try {
            $attempt = DB::transaction(function () use (
                $request,
                $opportunity,
                $input,
                $signature,
                $executionPolicy,
                $integrity,
            ): SolanaSwapAttempt {
                $lockedOpportunity = TradeOpportunity::query()->lockForUpdate()->findOrFail($opportunity->id);
                $attempt = SolanaSwapAttempt::query()->with('connectedWallet')->whereKey($input['attempt_id'])
                    ->where('trade_opportunity_id', $lockedOpportunity->id)
                    ->where('user_id', $request->user()->id)->lockForUpdate()->firstOrFail();
                $this->assertSubmissionAvailable($attempt, $signature);
                $integrity->assertValid($lockedOpportunity, $attempt);
                $integrity->assertHumanHandoff($attempt);
                $wallet = $attempt->connectedWallet;
                if ($lockedOpportunity->chain !== Chain::Solana
                    || $lockedOpportunity->execution_mode !== ExecutionMode::Live
                    || ! $executionPolicy->permitsSubmittedEvidence($lockedOpportunity)
                    || ! in_array($lockedOpportunity->status, [TradeOpportunityStatus::Executing, TradeOpportunityStatus::Expired], true)
                    || (int) data_get($lockedOpportunity->execution_data, 'solana_swap_attempt_id') !== $attempt->id
                    || $lockedOpportunity->address !== $attempt->output_mint
                    || ! $wallet || $wallet->user_id !== $request->user()->id
                    || $wallet->chain !== Chain::Solana || $wallet->address !== $attempt->wallet_address) {
                    throw new SolanaPreparationException('This opportunity is unavailable for Solana transaction reporting.', 409);
                }
                if ($attempt->transaction_signature === null) {
                    $attempt->update([
                        'status' => 'submitted', 'transaction_signature' => $signature, 'submitted_at' => now(),
                    ]);
                    $lockedOpportunity->update([
                        'status' => TradeOpportunityStatus::Executing,
                        'execution_data' => [...($lockedOpportunity->execution_data ?? []), 'stage' => 'submitted'],
                    ]);
                }

                return $attempt;
            });
        } catch (ModelNotFoundException) {
            abort(404);
        } catch (SolanaPreparationException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->httpStatus);
        } catch (QueryException) {
            Log::warning('Solana transaction report database operation failed.');

            return response()->json([
                'message' => 'The Solana report could not be saved. Retry the same signature; do not send another transaction.',
            ], 503);
        }

        return $this->submissionResponse($attempt);
    }

    private function assertSubmissionAvailable(SolanaSwapAttempt $attempt, string $signature): void
    {
        if ($attempt->transaction_signature !== null) {
            if (hash_equals($attempt->transaction_signature, $signature)
                && in_array($attempt->status, ['submitted', 'confirmed', 'failed'], true)) {
                return;
            }
            throw new RuntimeException('This Solana attempt already has a different transaction.');
        }
        if (! in_array($attempt->status, ['prepared', 'expired'], true)) {
            throw new RuntimeException('This Solana attempt is no longer available for transaction reporting.');
        }
    }

    private function owner(Request $request, TradeOpportunity $opportunity): void
    {
        abort_unless($opportunity->user_id === $request->user()->id, 404);
    }

    private function submissionResponse(SolanaSwapAttempt $attempt): JsonResponse
    {
        return response()->json(['swap' => [
            'status' => $attempt->status,
            'transaction_signature' => $attempt->transaction_signature,
        ]]);
    }

    private function databaseFailure(): JsonResponse
    {
        Log::warning('Solana signing claim database operation failed.');

        return response()->json([
            'message' => 'Solana signing handoff is unresolved. Refresh its status; do not send again.',
        ], 503);
    }
}
