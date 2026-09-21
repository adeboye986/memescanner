<?php

namespace App\Http\Controllers;

use App\Chain;
use App\Http\Requests\SolanaSwapQuoteRequest;
use App\Models\SolanaSwapAttempt;
use App\Services\JupiterSwapOrderService;
use App\Services\SolanaService;
use App\Services\TokenAmountFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            'recent_blockhash' => $order['validation']['recent_blockhash'],
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

    public function history(Request $request, TokenAmountFormatter $amounts): JsonResponse
    {
        $attempts = SolanaSwapAttempt::query()
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->limit(10)
            ->get([
                'id',
                'output_mint',
                'input_amount_lamports',
                'status',
                'transaction_signature',
                'submitted_at',
                'confirmed_at',
                'failed_at',
                'failure_reason',
                'network_fee_lamports',
                'slot',
                'created_at',
            ]);

        return response()->json([
            'transactions' => $attempts->map(function (SolanaSwapAttempt $attempt) use ($amounts): array {
                return [
                    'id' => $attempt->id,
                    'output_mint' => $attempt->output_mint,
                    'input_amount_lamports' => (string) $attempt->input_amount_lamports,
                    'input_amount_sol' => $amounts->format((string) $attempt->input_amount_lamports, 9),
                    'status' => $attempt->status,
                    'transaction_signature' => $attempt->transaction_signature,
                    'submitted_at' => $attempt->submitted_at?->toIso8601String(),
                    'confirmed_at' => $attempt->confirmed_at?->toIso8601String(),
                    'failed_at' => $attempt->failed_at?->toIso8601String(),
                    'failure_reason' => $attempt->failure_reason,
                    'network_fee_lamports' => $attempt->network_fee_lamports !== null
                        ? (string) $attempt->network_fee_lamports
                        : null,
                    'network_fee_sol' => $attempt->network_fee_lamports !== null
                        ? $amounts->format((string) $attempt->network_fee_lamports, 9)
                        : null,
                    'slot' => $attempt->slot,
                    'created_at' => $attempt->created_at?->toIso8601String(),
                ];
            })->values(),
        ]);
    }
}
