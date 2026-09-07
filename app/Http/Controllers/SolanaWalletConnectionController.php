<?php

namespace App\Http\Controllers;

use App\Services\SolanaWalletConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
