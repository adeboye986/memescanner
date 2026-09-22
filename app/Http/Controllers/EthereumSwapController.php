<?php

namespace App\Http\Controllers;

use App\Chain;
use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Enums\TradeOpportunityStatus;
use App\Exceptions\EthereumPreparationException;
use App\Http\Requests\EthereumSwapRequest;
use App\Models\EthereumSwapAttempt;
use App\Models\TradeOpportunity;
use App\Services\CryptoPriceService;
use App\Services\EthereumOpportunityPreparationService;
use App\Services\EthereumQuoteLimitService;
use App\Services\EthereumService;
use App\Services\EthereumSwapPreparationService;
use App\Services\TokenAmountFormatter;
use App\Services\ZeroXSwapService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class EthereumSwapController extends Controller
{
    public function price(EthereumSwapRequest $request, ZeroXSwapService $swaps, EthereumService $ethereum, EthereumQuoteLimitService $limits, CryptoPriceService $prices, TokenAmountFormatter $amounts): JsonResponse
    {
        $wallet = $this->wallet($request);
        if (! $wallet) {
            return response()->json(['message' => 'No active verified Ethereum wallet was found.'], 422);
        }
        try {
            $balance = $ethereum->getBalanceWei($wallet->address);
        } catch (RuntimeException) {
            return response()->json(['message' => 'Unable to verify the Ethereum wallet balance right now.'], 503);
        }
        if ($limits->exceeds($request->validated('sell_amount_wei'), $balance)) {
            return response()->json(['message' => 'The requested ETH amount exceeds the connected wallet balance.'], 422);
        }

        try {
            $price = $swaps->price($wallet->address, $request->validated('buy_token'), $request->validated('sell_amount_wei'), (int) $request->validated('slippage_bps'));
        } catch (RuntimeException) {
            return response()->json(['message' => 'Unable to retrieve an Ethereum swap price right now.'], 503);
        }

        $networkFeeWei = $price['network_fee_wei'] ?? null;

        if (is_string($networkFeeWei) && preg_match('/^\d+$/', $networkFeeWei) === 1) {
            $price['network_fee'] = [
                'wei' => $networkFeeWei,
                'eth' => $amounts->format($networkFeeWei, 18),
                'usd' => null,
            ];

            try {
                $ethUsdPrice = $prices->ethUsdPrice();

                $price['network_fee']['usd'] = $prices->usdForWei(
                    $networkFeeWei,
                    $ethUsdPrice,
                );
            } catch (RuntimeException) {
                // USD is display enrichment only. Keep the valid swap price usable.
            }
        } else {
            $price['network_fee'] = null;
        }

        return response()->json(['price' => $price]);
    }

    public function order(EthereumSwapRequest $request, EthereumSwapPreparationService $preparation): JsonResponse
    {
        try {
            $attempt = $preparation->manual($request->user(), $request->validated());
        } catch (EthereumPreparationException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->httpStatus);
        } catch (QueryException $exception) {
            Log::warning('Ethereum swap database operation failed.');

            return response()->json(['message' => 'Ethereum preparation could not be saved. Please retry.'], 503);
        }

        return response()->json(['order' => [
            'attempt_id' => $attempt->id,
            'transaction' => $attempt->transaction_payload,
            'expires_at' => $attempt->expires_at->toIso8601String(),
        ]]);
    }

    public function submitted(Request $request, EthereumService $ethereum): JsonResponse
    {
        $validated = $request->validate([
            'attempt_id' => ['required', 'integer'],
            'transaction_hash' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{64}$/'],
        ]);

        $submittedHash = strtolower($validated['transaction_hash']);
        $attempt = EthereumSwapAttempt::query()
            ->whereKey($validated['attempt_id'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        try {
            $this->assertSubmissionAvailable($attempt, $submittedHash);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        if ($attempt->transaction_hash !== null) {
            return $this->submissionResponse($attempt);
        }

        try {
            $transaction = $ethereum->getTransactionByHash($submittedHash);
        } catch (RuntimeException) {
            return response()->json([
                'message' => 'The broadcast Ethereum transaction could not be verified. Retry reporting the same transaction hash; do not send another transaction.',
                'retryable' => true,
                'attempt_id' => $attempt->id,
                'transaction_hash' => $submittedHash,
            ], 503);
        }

        try {
            $attempt = DB::transaction(function () use ($request, $validated, $submittedHash, $transaction, $ethereum, $attempt): EthereumSwapAttempt {
                $opportunity = $attempt->trade_opportunity_id
                    ? TradeOpportunity::query()->lockForUpdate()->find($attempt->trade_opportunity_id)
                    : null;
                $attempt = EthereumSwapAttempt::query()
                    ->with('connectedWallet')
                    ->whereKey($validated['attempt_id'])
                    ->where('user_id', $request->user()->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->assertSubmissionAvailable($attempt, $submittedHash);

                if ($attempt->transaction_hash !== null) {
                    return $attempt;
                }

                if ($attempt->trade_opportunity_id !== null && (! $opportunity
                    || $opportunity->id !== $attempt->trade_opportunity_id
                    || $opportunity->chain !== Chain::Ethereum
                    || $opportunity->execution_mode !== ExecutionMode::Live
                    || $opportunity->entry_mode !== EntryMode::Confirm
                    || strtolower($opportunity->address) !== $attempt->buy_token
                    || (int) data_get($opportunity->execution_data, 'ethereum_swap_attempt_id') !== $attempt->id
                    || ! in_array($opportunity->status, [TradeOpportunityStatus::Executing, TradeOpportunityStatus::Expired], true))) {
                    throw new RuntimeException('This opportunity is no longer available for transaction reporting.');
                }

                $wallet = $attempt->connectedWallet;
                if (! $wallet
                    || $wallet->chain !== Chain::Ethereum
                    || ($attempt->trade_opportunity_id !== null && (! $opportunity || $opportunity->user_id !== $request->user()->id || $wallet->user_id !== $request->user()->id || strtolower($wallet->address) !== $attempt->wallet_address))
                    || ! $ethereum->matchesPreparedTransaction($transaction, $attempt->transaction_payload ?? [], $submittedHash, $wallet->address)) {
                    throw new RuntimeException('The broadcast Ethereum transaction does not match the prepared swap.');
                }

                $attempt->update([
                    'status' => 'submitted',
                    'transaction_hash' => $submittedHash,
                    'submitted_at' => now(),
                ]);
                if ($opportunity) {
                    $opportunity->update(['status' => TradeOpportunityStatus::Executing, 'execution_data' => [...($opportunity->execution_data ?? []), 'stage' => 'submitted']]);
                }

                return $attempt;
            });
        } catch (ModelNotFoundException) {
            abort(404);
        } catch (QueryException $exception) {
            Log::warning('Ethereum swap database operation failed.');

            return response()->json(['message' => 'The Ethereum swap update could not be saved. Retry reporting the same attempt; do not send another transaction.'], 503);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return $this->submissionResponse($attempt);
    }

    private function assertSubmissionAvailable(EthereumSwapAttempt $attempt, string $hash): void
    {
        if ($attempt->transaction_hash !== null) {
            if (strtolower($attempt->transaction_hash) === $hash
                && in_array($attempt->status, ['submitted', 'confirmed', 'failed'], true)) {
                return;
            }

            throw new RuntimeException('This Ethereum swap order already has a different transaction or is no longer available for submission.');
        }

        if (! in_array($attempt->status, ['prepared', 'expired'], true)) {
            throw new RuntimeException('This Ethereum swap order is no longer available for submission.');
        }
    }

    private function submissionResponse(EthereumSwapAttempt $attempt): JsonResponse
    {
        return response()->json([
            'swap' => [
                'status' => $attempt->status,
                'transaction_hash' => $attempt->transaction_hash,
            ],
        ]);
    }

    public function cancelled(Request $request, EthereumOpportunityPreparationService $opportunities): JsonResponse
    {
        $validated = $request->validate([
            'attempt_id' => ['required', 'integer'],
        ]);

        $original = EthereumSwapAttempt::query()->whereKey($validated['attempt_id'])->where('user_id', $request->user()->id)->firstOrFail();
        if ($original->trade_opportunity_id !== null) {
            try {
                $attempt = $opportunities->cancel($original, $request->user());
            } catch (EthereumPreparationException $exception) {
                return response()->json(['message' => $exception->getMessage()], $exception->httpStatus);
            } catch (QueryException $exception) {
                Log::warning('Ethereum swap database operation failed.');

                return response()->json(['message' => 'Cancellation could not be saved. Refresh the opportunity.'], 503);
            }

            return $this->submissionResponse($attempt);
        }

        try {
            $attempt = DB::transaction(function () use ($request, $validated): EthereumSwapAttempt {
                $attempt = EthereumSwapAttempt::query()
                    ->whereKey($validated['attempt_id'])
                    ->where('user_id', $request->user()->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($attempt->status !== 'prepared') {
                    throw new RuntimeException('This Ethereum swap order can no longer be cancelled.');
                }

                if ($attempt->expires_at->isPast()) {
                    $attempt->update(['status' => 'expired']);

                    return $attempt;
                }

                $attempt->update(['status' => 'cancelled']);

                return $attempt;
            });
        } catch (ModelNotFoundException) {
            abort(404);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'swap' => [
                'status' => $attempt->status,
            ],
        ]);
    }

    public function history(Request $request, TokenAmountFormatter $amounts): JsonResponse
    {
        $attempts = EthereumSwapAttempt::query()
            ->where('user_id', $request->user()->id)
            ->whereNotIn('status', ['reserved', 'preparing', 'released'])
            ->where(fn ($query) => $query->whereNull('trade_opportunity_id')->orWhereNotNull('transaction_payload')->orWhereNotNull('transaction_hash'))
            ->latest('id')
            ->limit(10)
            ->get([
                'id',
                'buy_token',
                'sell_amount_wei',
                'status',
                'transaction_hash',
                'submitted_at',
                'confirmed_at',
                'failed_at',
                'failure_reason',
                'block_number',
                'gas_used',
                'effective_gas_price_wei',
                'actual_network_fee_wei',
                'created_at',
            ]);

        return response()->json([
            'transactions' => $attempts->map(function (EthereumSwapAttempt $attempt) use ($amounts): array {
                return [
                    'id' => $attempt->id,
                    'buy_token' => $attempt->buy_token,
                    'sell_amount_wei' => $attempt->sell_amount_wei,
                    'sell_amount_eth' => $amounts->format($attempt->sell_amount_wei, 18),
                    'status' => $attempt->status,
                    'transaction_hash' => $attempt->transaction_hash,
                    'submitted_at' => $attempt->submitted_at?->toIso8601String(),
                    'confirmed_at' => $attempt->confirmed_at?->toIso8601String(),
                    'failed_at' => $attempt->failed_at?->toIso8601String(),
                    'failure_reason' => $attempt->failure_reason,
                    'block_number' => $attempt->block_number,
                    'gas_used' => $attempt->gas_used,
                    'effective_gas_price_wei' => $attempt->effective_gas_price_wei,
                    'actual_network_fee_wei' => $attempt->actual_network_fee_wei,
                    'actual_network_fee_eth' => $attempt->actual_network_fee_wei
                        ? $amounts->format($attempt->actual_network_fee_wei, 18)
                        : null,
                    'created_at' => $attempt->created_at?->toIso8601String(),
                ];
            })->values(),
        ]);
    }

    private function wallet(Request $request): mixed
    {
        return $request->user()->connectedWallets()->verifiedEthereum()->first();
    }
}
