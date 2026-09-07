<?php

namespace App\Http\Controllers;

use App\Chain;
use App\Services\SolanaService;
use App\Services\SolanaWalletConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class SolanaWalletConnectionController extends Controller
{
    public function challenge(
        Request $request,
        SolanaWalletConnectionService $wallets,
    ): JsonResponse {
        $validated = $request->validate([
            'address' => ['required', 'string', 'max:191'],
            'provider' => ['nullable', 'string', 'in:phantom,solflare,compatible'],
        ]);

        $challenge = $wallets->createChallenge(
            $request->user(),
            $validated['address'],
            $validated['provider'] ?? null,
        );

        return response()->json([
            'challenge_id' => $challenge->id,
            'message' => $challenge->message,
            'expires_at' => $challenge->expires_at->toIso8601String(),
        ]);
    }

    public function verify(
        Request $request,
        SolanaWalletConnectionService $wallets,
    ): JsonResponse {
        $validated = $request->validate([
            'challenge_id' => ['required', 'integer'],
            'signature' => ['required', 'string', 'max:500'],
        ]);

        $wallet = $wallets->verifyChallenge(
            $request->user(),
            (int) $validated['challenge_id'],
            $validated['signature'],
        );

        return response()->json([
            'verified' => true,
            'wallet' => [
                'chain' => $wallet->chain->value,
                'address' => $wallet->address,
                'provider' => $wallet->provider,
                'verified_at' => $wallet->verified_at->toIso8601String(),
            ],
        ]);
    }

    public function balance(
        Request $request,
        SolanaService $solana,
    ): JsonResponse {
        $wallet = $request->user()
            ->connectedWallets()
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
            $lamports = $solana->getBalanceLamports($wallet->address);
        } catch (RuntimeException) {
            return response()->json([
                'message' => 'Unable to read the Solana wallet balance right now.',
            ], 503);
        }

        $whole = intdiv($lamports, 1_000_000_000);
        $fraction = $lamports % 1_000_000_000;

        return response()->json([
            'balance' => [
                'chain' => Chain::Solana->value,
                'lamports' => $lamports,
                'sol' => $whole.'.'.str_pad((string) $fraction, 9, '0', STR_PAD_LEFT),
            ],
        ]);
    }

    public function disconnect(
        Request $request,
        SolanaWalletConnectionService $wallets,
    ): JsonResponse {
        $wallet = $wallets->disconnect($request->user());

        return response()->json([
            'disconnected' => true,
            'message' => 'The verified Solana wallet was disconnected from this account.',
            'wallet' => [
                'chain' => $wallet->chain->value,
                'disconnected_at' => $wallet->disconnected_at->toIso8601String(),
            ],
        ]);
    }
}
