<?php

namespace App\Http\Controllers;

use App\Chain;
use App\Http\Requests\EthereumSwapRequest;
use App\Models\EthereumSwapAttempt;
use App\Services\CryptoPriceService;
use App\Services\EthereumQuoteLimitService;
use App\Services\EthereumService;
use App\Services\TokenAmountFormatter;
use App\Services\ZeroXSwapService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    public function order(EthereumSwapRequest $request, ZeroXSwapService $swaps, EthereumService $ethereum, EthereumQuoteLimitService $limits): JsonResponse
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
            return response()->json(['message' => 'The connected wallet has insufficient ETH for this swap.'], 422);
        }

        try {
            $quote = $swaps->quote($wallet->address, $request->validated('buy_token'), $request->validated('sell_amount_wei'), (int) $request->validated('slippage_bps'));
        } catch (RuntimeException) {
            return response()->json(['message' => 'Unable to prepare the Ethereum swap right now.'], 503);
        }
        $requiredBalance = $this->addUnsignedIntegers(
            $request->validated('sell_amount_wei'),
            $quote['network_fee_wei'] ?? '0',
        );
        if ($limits->exceeds($requiredBalance, $balance)) {
            return response()->json(['message' => 'The connected wallet has insufficient ETH for the swap and estimated network fee.'], 422);
        }
        $attempt = EthereumSwapAttempt::query()->create([
            'user_id' => $request->user()->id,
            'connected_wallet_id' => $wallet->id,
            'buy_token' => strtolower($request->validated('buy_token')),
            'sell_amount_wei' => $request->validated('sell_amount_wei'),
            'slippage_bps' => $request->validated('slippage_bps'),
            'quote_id' => $quote['quote_id'],
            'transaction_payload' => $quote['transaction'],
            'status' => 'prepared',
            'expires_at' => now()->addMinute(),
        ]);

        return response()->json(['order' => [
            'attempt_id' => $attempt->id,
            'transaction' => $quote['transaction'],
            'expires_at' => $attempt->expires_at->toIso8601String(),
        ]]);
    }

    public function submitted(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'attempt_id' => ['required', 'integer'],
            'transaction_hash' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{64}$/'],
        ]);
        try {
            $attempt = DB::transaction(function () use ($request, $validated): EthereumSwapAttempt {
                $attempt = EthereumSwapAttempt::query()->whereKey($validated['attempt_id'])
                    ->where('user_id', $request->user()->id)->lockForUpdate()->firstOrFail();
                if ($attempt->status !== 'prepared') {
                    throw new RuntimeException('This Ethereum swap order is no longer available for submission.');
                }

                if ($attempt->expires_at->isPast()) {
                    $attempt->update(['status' => 'expired']);

                    return $attempt;
                }

                $attempt->update([
                    'status' => 'submitted',
                    'transaction_hash' => strtolower($validated['transaction_hash']),
                    'submitted_at' => now(),
                ]);

                return $attempt;
            });
        } catch (ModelNotFoundException) {
            abort(404);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        if ($attempt->status === 'expired') {
            return response()->json([
                'message' => 'This Ethereum swap order has expired.',
            ], 422);
        }

        return response()->json([
            'swap' => [
                'status' => $attempt->status,
                'transaction_hash' => $attempt->transaction_hash,
            ],
        ]);
    }

    public function cancelled(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'attempt_id' => ['required', 'integer'],
        ]);

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

    private function wallet(Request $request): mixed
    {
        return $request->user()->connectedWallets()->where('chain', Chain::Ethereum->value)
            ->whereNotNull('verified_at')->whereNull('disconnected_at')->first();
    }

    private function addUnsignedIntegers(string $left, string $right): string
    {
        $carry = 0;
        $sum = '';
        $leftIndex = strlen($left) - 1;
        $rightIndex = strlen($right) - 1;
        while ($leftIndex >= 0 || $rightIndex >= 0 || $carry > 0) {
            $total = ($leftIndex >= 0 ? (int) $left[$leftIndex--] : 0)
                + ($rightIndex >= 0 ? (int) $right[$rightIndex--] : 0)
                + $carry;
            $sum = ($total % 10).$sum;
            $carry = intdiv($total, 10);
        }

        return ltrim($sum, '0') ?: '0';
    }
}
