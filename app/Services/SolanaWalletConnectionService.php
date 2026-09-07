<?php

namespace App\Services;

use App\Chain;
use App\Models\ConnectedWallet;
use App\Models\User;
use App\Models\WalletConnectionChallenge;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SolanaWalletConnectionService
{
    private const CHALLENGE_MINUTES = 10;

    public function createChallenge(
        User $user,
        string $address,
        ?string $provider = null,
    ): WalletConnectionChallenge {
        $address = trim($address);

        $publicKey = $this->decodeBase58($address);

        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw ValidationException::withMessages([
                'address' => 'The Solana wallet address is invalid.',
            ]);
        }

        $provider = $this->normalizeProvider($provider);

        $nonce = bin2hex(random_bytes(32));

        $issuedAt = now()->utc();
        $expiresAt = $issuedAt->copy()->addMinutes(self::CHALLENGE_MINUTES);

        $appUrl = rtrim((string) config('app.url'), '/');
        $domain = parse_url($appUrl, PHP_URL_HOST) ?: $appUrl;

        $configuredName = app(ApplicationSettingsService::class)->get('general.application_name');
        $applicationName = is_string($configuredName) && trim($configuredName) !== ''
            ? trim($configuredName)
            : trim((string) config('app.name'));

        $message = implode("\n", [
            $applicationName.' Wallet Verification',
            '',
            'Domain: '.$domain,
            'Account: '.$user->id,
            'Chain: '.Chain::Solana->value,
            'Wallet: '.$address,
            'Nonce: '.$nonce,
            'Issued At: '.$issuedAt->toIso8601String(),
            'Expires At: '.$expiresAt->toIso8601String(),
            '',
            'Signing this message proves wallet ownership only.',
            'It does not authorize a transaction.',
        ]);

        return $user->walletConnectionChallenges()->create([
            'chain' => Chain::Solana,
            'address' => $address,
            'provider' => $provider,
            'nonce' => $nonce,
            'message' => $message,
            'expires_at' => $expiresAt,
        ]);
    }

    public function verifyChallenge(
        User $user,
        int $challengeId,
        string $signature,
    ): ConnectedWallet {
        return DB::transaction(function () use ($user, $challengeId, $signature): ConnectedWallet {
            $challenge = WalletConnectionChallenge::query()
                ->whereKey($challengeId)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (! $challenge) {
                throw ValidationException::withMessages([
                    'challenge_id' => 'Wallet verification challenge was not found.',
                ]);
            }

            if ($challenge->isUsed()) {
                throw ValidationException::withMessages([
                    'challenge_id' => 'This wallet verification challenge has already been used.',
                ]);
            }

            if ($challenge->isExpired()) {
                throw ValidationException::withMessages([
                    'challenge_id' => 'This wallet verification challenge has expired.',
                ]);
            }

            $publicKey = $this->decodeBase58($challenge->address);

            $decodedSignature = base64_decode($signature, true);

            if (
                $decodedSignature === false
                || strlen($decodedSignature) !== SODIUM_CRYPTO_SIGN_BYTES
            ) {
                throw ValidationException::withMessages([
                    'signature' => 'The wallet signature is invalid.',
                ]);
            }

            if (! sodium_crypto_sign_verify_detached(
                $decodedSignature,
                $challenge->message,
                $publicKey,
            )) {
                throw ValidationException::withMessages([
                    'signature' => 'Wallet ownership verification failed.',
                ]);
            }

            $addressHash = ConnectedWallet::addressHash(
                Chain::Solana,
                $challenge->address,
            );

            $claimed = ConnectedWallet::query()
                ->where('address_hash', $addressHash)
                ->where('user_id', '!=', $user->id)
                ->exists();

            if ($claimed) {
                throw ValidationException::withMessages([
                    'address' => 'This wallet is already connected to another account.',
                ]);
            }

            $wallet = ConnectedWallet::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'chain' => Chain::Solana->value,
                ],
                [
                    'address' => $challenge->address,
                    'address_hash' => $addressHash,
                    'provider' => $challenge->provider,
                    'verified_at' => now(),
                    'last_connected_at' => now(),
                    'disconnected_at' => null,
                ],
            );

            $challenge->update([
                'used_at' => now(),
            ]);

            return $wallet;
        });
    }

    public function disconnect(User $user): ConnectedWallet
    {
        return DB::transaction(function () use ($user): ConnectedWallet {
            $wallet = $user->connectedWallets()
                ->where('chain', Chain::Solana->value)
                ->whereNotNull('verified_at')
                ->whereNull('disconnected_at')
                ->lockForUpdate()
                ->first();

            if (! $wallet) {
                throw ValidationException::withMessages([
                    'wallet' => 'No active verified Solana wallet was found for this account.',
                ]);
            }

            $wallet->update([
                'disconnected_at' => now(),
            ]);

            return $wallet;
        });
    }

    private function normalizeProvider(?string $provider): ?string
    {
        if ($provider === null || trim($provider) === '') {
            return null;
        }

        $provider = strtolower(trim($provider));

        if (! in_array($provider, ['phantom', 'solflare', 'compatible'], true)) {
            throw ValidationException::withMessages([
                'provider' => 'Unsupported Solana wallet provider.',
            ]);
        }

        return $provider;
    }

    private function decodeBase58(string $value): string
    {
        if ($value === '') {
            throw ValidationException::withMessages([
                'address' => 'A Solana wallet address is required.',
            ]);
        }

        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

        $bytes = [0];

        foreach (str_split($value) as $character) {
            $position = strpos($alphabet, $character);

            if ($position === false) {
                throw ValidationException::withMessages([
                    'address' => 'The Solana wallet address is invalid.',
                ]);
            }

            $carry = $position;

            for ($i = 0, $count = count($bytes); $i < $count; $i++) {
                $carry += $bytes[$i] * 58;
                $bytes[$i] = $carry & 0xFF;
                $carry >>= 8;
            }

            while ($carry > 0) {
                $bytes[] = $carry & 0xFF;
                $carry >>= 8;
            }
        }

        $leadingZeros = 0;

        for ($i = 0, $length = strlen($value); $i < $length && $value[$i] === '1'; $i++) {
            $leadingZeros++;
        }

        $decoded = str_repeat("\x00", $leadingZeros);

        for ($i = count($bytes) - 1; $i >= 0; $i--) {
            if ($i === count($bytes) - 1 && $bytes[$i] === 0) {
                continue;
            }

            $decoded .= chr($bytes[$i]);
        }

        return $decoded;
    }
}
