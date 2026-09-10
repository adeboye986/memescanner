<?php

namespace App\Http\Controllers;

use App\Chain;
use App\Http\Requests\SolanaSwapQuoteRequest;
use App\Models\SolanaSwapAttempt;
use App\Services\JupiterSwapOrderService;
use App\Services\SolanaService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class SolanaSwapOrderController extends Controller
{
    public function __invoke(
        SolanaSwapQuoteRequest $request,
        JupiterSwapOrderService $orders,
        SolanaService $solana,
    ): JsonResponse {
        $wallet = $request->user()->connectedWallets()
            ->where('chain', Chain::Solana->value)
            ->whereNotNull('verified_at')
            ->whereNull('disconnected_at')
            ->first();

        if (! $wallet) {
            return response()->json(['message' => 'No active verified Solana wallet was found for this account.'], 422);
        }

        try {
            $balance = $solana->getBalanceLamports($wallet->address);
        } catch (\Throwable) {
            return response()->json(['message' => 'Unable to verify the available SOL balance right now.'], 503);
        }

        if ((int) $request->validated('amount') > $balance) {
            return response()->json(['message' => 'The requested SOL amount exceeds the connected wallet balance.'], 422);
        }

        try {
            $order = $orders->prepare(
                $wallet->address,
                $request->validated('output_mint'),
                (string) $request->validated('amount'),
                (int) $request->validated('slippage_bps'),
            );
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage() === 'Insufficient funds.'
                ? 'The connected wallet needs enough SOL for the swap and network fees.'
                : 'Unable to prepare the Solana swap right now.';

            return response()->json(['message' => $message], 422);
        }

        $attempt = SolanaSwapAttempt::query()->create([
            'user_id' => $request->user()->id,
            'connected_wallet_id' => $wallet->id,
            'request_id' => $order['request_id'],
            'output_mint' => $request->validated('output_mint'),
            'input_amount_lamports' => $request->validated('amount'),
            'slippage_bps' => $request->validated('slippage_bps'),
            'message_hash' => $order['validation']['message_hash'],
            'prepared_transaction' => $order['transaction'],
            'expires_at' => now()->addMinutes(2),
        ]);

        return response()->json([
            'order' => [
                'attempt_id' => $attempt->id,
                'transaction' => $order['transaction'],
                'expires_at' => $attempt->expires_at->toIso8601String(),
            ],
        ]);
    }
}
