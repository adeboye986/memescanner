<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class RemoteEthereumSignatureValidator
{
    public function verify(string $message, string $signature, string $expectedWallet): string
    {
        $url = rtrim((string) config('services.solana_transaction_validator.url'), '/');
        $apiKey = (string) config('services.solana_transaction_validator.api_key');

        if ($url === '' || $apiKey === '' || ! str_starts_with($url, 'https://')) {
            throw new RuntimeException('Ethereum signature validator is not configured securely.');
        }

        try {
            $response = Http::acceptJson()->asJson()->withToken($apiKey)
                ->connectTimeout(3)->timeout(5)->withoutRedirecting()
                ->post($url.'/v1/verify-ethereum-signature', [
                    'message' => $message,
                    'signature' => $signature,
                    'expected_wallet' => $expectedWallet,
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Ethereum signature validator is unavailable.', previous: $exception);
        }

        $recovered = $response->json('recovered_address');
        if (! $response->successful() || $response->json('valid') !== true
            || ! is_string($recovered) || strtolower($recovered) !== strtolower($expectedWallet)) {
            throw new RuntimeException('Ethereum wallet ownership verification failed.');
        }

        return strtolower($recovered);
    }
}
