<?php

namespace App\Http\Controllers;

use App\Chain;
use App\Services\CryptoPriceService;
use App\Services\EthereumService;
use App\Services\EthereumWalletConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class EthereumWalletConnectionController extends Controller
{
    public function challenge(Request $request, EthereumWalletConnectionService $wallets): JsonResponse
    {
        $validated = $request->validate([
            'address' => ['required', 'string', 'max:191'],
            'provider' => ['required', 'string', 'in:phantom,metamask,compatible'],
        ]);
        $challenge = $wallets->createChallenge($request->user(), $validated['address'], $validated['provider']);

        return response()->json([
            'challenge_id' => $challenge->id,
            'message' => $challenge->message,
            'expires_at' => $challenge->expires_at->toIso8601String(),
        ]);
    }

    public function verify(Request $request, EthereumWalletConnectionService $wallets): JsonResponse
    {
        $validated = $request->validate([
            'challenge_id' => ['required', 'integer'],
            'signature' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{130}$/'],
        ]);
        $wallet = $wallets->verifyChallenge($request->user(), (int) $validated['challenge_id'], $validated['signature']);

        return response()->json(['verified' => true, 'wallet' => [
            'chain' => Chain::Ethereum->value,
            'address' => $wallet->address,
            'provider' => $wallet->provider,
            'verified_at' => $wallet->verified_at->toIso8601String(),
        ]]);
    }

    public function balance(Request $request, EthereumService $ethereum, CryptoPriceService $prices): JsonResponse
    {
        $wallet = $request->user()->connectedWallets()->where('chain', Chain::Ethereum->value)
            ->whereNotNull('verified_at')->whereNull('disconnected_at')->first();
        if (! $wallet) {
            return response()->json(['message' => 'No active verified Ethereum wallet was found.'], 422);
        }

        try {
            $wei = $ethereum->getBalanceWei($wallet->address);
        } catch (RuntimeException) {
            return response()->json(['message' => 'Unable to read the Ethereum wallet balance right now.'], 503);
        }
        $eth = $this->formatWei($wei);
        try {
            $ethUsd = $prices->ethUsdPrice();
            $usd = $prices->usdForWei($wei, $ethUsd);
        } catch (RuntimeException) {
            $ethUsd = null;
            $usd = null;
        }

        return response()->json([
            'balance' => ['chain' => Chain::Ethereum->value, 'wei' => $wei, 'eth' => $eth, 'usd' => $usd],
            'price' => ['eth_usd' => $ethUsd],
        ]);
    }

    public function disconnect(Request $request, EthereumWalletConnectionService $wallets): JsonResponse
    {
        $wallet = $wallets->disconnect($request->user());

        return response()->json(['disconnected' => true, 'wallet' => [
            'chain' => Chain::Ethereum->value,
            'disconnected_at' => $wallet->disconnected_at->toIso8601String(),
        ]]);
    }

    private function formatWei(string $wei): string
    {
        $padded = str_pad($wei, 19, '0', STR_PAD_LEFT);
        $whole = substr($padded, 0, -18);
        $fraction = rtrim(substr($padded, -18), '0');

        return $fraction === '' ? $whole.'.0' : $whole.'.'.$fraction;
    }
}
