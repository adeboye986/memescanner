<?php

namespace App\Services;

use App\Chain;
use App\Models\ConnectedWallet;
use App\Models\User;
use App\Models\WalletConnectionChallenge;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EthereumWalletConnectionService
{
    public function __construct(private RemoteEthereumSignatureValidator $validator) {}

    public function createChallenge(User $user, string $address, ?string $provider): WalletConnectionChallenge
    {
        $address = $this->normalizeAddress($address);
        $provider = $this->normalizeProvider($provider);
        $issuedAt = now()->utc();
        $expiresAt = $issuedAt->copy()->addMinutes(10);
        $nonce = bin2hex(random_bytes(32));
        $appUrl = rtrim((string) config('app.url'), '/');
        $domain = parse_url($appUrl, PHP_URL_HOST) ?: $appUrl;
        $configuredName = app(ApplicationSettingsService::class)->get('general.application_name');
        $applicationName = is_string($configuredName) && trim($configuredName) !== ''
            ? trim($configuredName) : trim((string) config('app.name'));

        $message = implode("\n", [
            $applicationName.' Wallet Verification', '',
            'Domain: '.$domain,
            'Account: '.$user->id,
            'Chain: '.Chain::Ethereum->value,
            'Wallet: '.$address,
            'Nonce: '.$nonce,
            'Issued At: '.$issuedAt->toIso8601String(),
            'Expires At: '.$expiresAt->toIso8601String(), '',
            'Signing this message proves wallet ownership only.',
            'It does not authorize a transaction.',
        ]);

        return $user->walletConnectionChallenges()->create([
            'chain' => Chain::Ethereum,
            'address' => $address,
            'provider' => $provider,
            'nonce' => $nonce,
            'message' => $message,
            'expires_at' => $expiresAt,
        ]);
    }

    public function verifyChallenge(User $user, int $challengeId, string $signature): ConnectedWallet
    {
        return DB::transaction(function () use ($user, $challengeId, $signature): ConnectedWallet {
            $challenge = WalletConnectionChallenge::query()->whereKey($challengeId)
                ->where('user_id', $user->id)->where('chain', Chain::Ethereum->value)
                ->lockForUpdate()->first();

            if (! $challenge || $challenge->isUsed() || $challenge->isExpired()) {
                throw ValidationException::withMessages(['challenge_id' => 'This Ethereum wallet challenge is invalid or expired.']);
            }

            try {
                $this->validator->verify($challenge->message, $signature, $challenge->address);
            } catch (\RuntimeException) {
                throw ValidationException::withMessages(['signature' => 'Ethereum wallet ownership verification failed.']);
            }

            $hash = ConnectedWallet::addressHash(Chain::Ethereum, $challenge->address);
            if (ConnectedWallet::query()->where('address_hash', $hash)->where('user_id', '!=', $user->id)->exists()) {
                throw ValidationException::withMessages(['address' => 'This wallet is already connected to another account.']);
            }

            $wallet = ConnectedWallet::query()->updateOrCreate(
                ['user_id' => $user->id, 'chain' => Chain::Ethereum->value],
                [
                    'address' => $challenge->address,
                    'address_hash' => $hash,
                    'provider' => $challenge->provider,
                    'verified_at' => now(),
                    'last_connected_at' => now(),
                    'disconnected_at' => null,
                ],
            );
            $challenge->update(['used_at' => now()]);

            return $wallet;
        });
    }

    public function disconnect(User $user): ConnectedWallet
    {
        return DB::transaction(function () use ($user): ConnectedWallet {
            $wallet = $user->connectedWallets()->where('chain', Chain::Ethereum->value)
                ->whereNotNull('verified_at')->whereNull('disconnected_at')->lockForUpdate()->first();
            if (! $wallet) {
                throw ValidationException::withMessages(['wallet' => 'No active verified Ethereum wallet was found.']);
            }
            $wallet->update(['disconnected_at' => now()]);

            return $wallet;
        });
    }

    public function isValidAddress(string $address): bool
    {
        return preg_match('/^0x[a-fA-F0-9]{40}$/', trim($address)) === 1;
    }

    private function normalizeAddress(string $address): string
    {
        if (! $this->isValidAddress($address)) {
            throw ValidationException::withMessages(['address' => 'The Ethereum wallet address is invalid.']);
        }

        return strtolower(trim($address));
    }

    private function normalizeProvider(?string $provider): string
    {
        $provider = strtolower(trim((string) $provider));
        if (! in_array($provider, ['phantom', 'metamask', 'compatible'], true)) {
            throw ValidationException::withMessages(['provider' => 'Unsupported Ethereum wallet provider.']);
        }

        return $provider;
    }
}
