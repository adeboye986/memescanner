<?php

namespace App\Http\Controllers;

use App\Chain;
use App\Http\Requests\SolanaSwapQuoteRequest;
use App\Services\CryptoPriceService;
use App\Services\SolanaSwapQuoteService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class SolanaSwapQuoteController extends Controller
{
    public function __invoke(
        SolanaSwapQuoteRequest $request,
        SolanaSwapQuoteService $quotes,
        CryptoPriceService $prices,
    ): JsonResponse {
        $wallet = $request->user()->connectedWallets()
            ->where('chain', Chain::Solana->value)
            ->whereNotNull('verified_at')
            ->whereNull('disconnected_at')
            ->first();

        if (! $wallet) {
            return response()->json([
                'message' => 'No active verified Solana wallet was found for this account.',
            ], 422);
        }

        try {
            $quote = $quotes->quote(
                $request->validated('output_mint'),
                (string) $request->validated('amount'),
                (int) $request->validated('slippage_bps'),
            );
        } catch (RuntimeException) {
            return response()->json([
                'message' => 'Unable to retrieve a Solana swap quote right now.',
            ], 503);
        }

        try {
            $solUsd = $prices->solUsdPrice();
            $spendUsd = $prices->usdForLamports((int) $request->validated('amount'), $solUsd);
        } catch (RuntimeException) {
            $solUsd = null;
            $spendUsd = null;
        }

        return response()->json([
            'quote' => [
                ...$quote,
                'spend_usd' => $spendUsd,
            ],
            'price' => [
                'sol_usd' => $solUsd,
            ],
        ]);
    }
}
