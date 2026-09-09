<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class RemoteSolanaTransactionValidator
{
    public function inspectPrepared(
        string $transaction,
        string $expectedWallet
    ): array {
        return $this->request('/v1/inspect-prepared', [
            'transaction' => $transaction,
            'expected_wallet' => $expectedWallet,
        ]);
    }

    public function compareSigned(
        string $preparedTransaction,
        string $signedTransaction,
        string $expectedWallet
    ): array {
        return $this->request('/v1/compare-signed', [
            'prepared_transaction' => $preparedTransaction,
            'signed_transaction' => $signedTransaction,
            'expected_wallet' => $expectedWallet,
        ]);
    }

    private function request(string $path, array $payload): array
    {
        $baseUrl = rtrim(
            (string) config('services.solana_transaction_validator.url'),
            '/'
        );

        $apiKey = (string) config(
            'services.solana_transaction_validator.api_key'
        );

        $timeout = max(
            1,
            (int) config(
                'services.solana_transaction_validator.timeout_seconds',
                5
            )
        );

        if ($baseUrl === '' || $apiKey === '') {
            throw new RuntimeException(
                'Solana transaction validator is not configured.'
            );
        }

        if (! str_starts_with($baseUrl, 'https://')) {
            throw new RuntimeException(
                'Solana transaction validator requires HTTPS.'
            );
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withToken($apiKey)
                ->connectTimeout(min($timeout, 3))
                ->timeout($timeout)
                ->withoutRedirecting()
                ->post($baseUrl.$path, $payload);
        } catch (ConnectionException) {
            throw new RuntimeException(
                'Solana transaction validator is unavailable.'
            );
        }

        return $this->normalizeResponse($response);
    }

    private function normalizeResponse(Response $response): array
    {
        if (! $response->successful()) {
            throw new RuntimeException(
                'Solana transaction validation failed.'
            );
        }

        $result = $response->json();

        if (! is_array($result) || ($result['valid'] ?? null) !== true) {
            throw new RuntimeException(
                'Solana transaction validator returned an invalid response.'
            );
        }

        if (
            ! isset($result['transaction_version']) ||
            (int) $result['transaction_version'] !== 0 ||
            ! is_string($result['message_hash'] ?? null) ||
            preg_match('/^[a-f0-9]{64}$/', $result['message_hash']) !== 1 ||
            ! is_string($result['fee_payer'] ?? null) ||
            $result['fee_payer'] === '' ||
            ! is_array($result['required_signer_addresses'] ?? null) ||
            ! is_bool($result['expected_wallet_is_required_signer'] ?? null) ||
            ! is_bool($result['expected_wallet_is_fee_payer'] ?? null) ||
            ! is_int($result['signature_count'] ?? null) ||
            ! is_bool($result['expected_wallet_signature_present'] ?? null) ||
            ! is_string($result['recent_blockhash'] ?? null) ||
            ! is_array($result['address_lookup_table_references'] ?? null)
        ) {
            throw new RuntimeException(
                'Solana transaction validator returned an invalid response.'
            );
        }

        return $result;
    }
}
