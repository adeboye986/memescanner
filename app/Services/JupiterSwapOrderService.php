<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class JupiterSwapOrderService
{
    public function __construct(
        private RemoteSolanaTransactionValidator $validator,
        private SolanaWalletConnectionService $wallets,
    ) {}

    /**
     * Prepare a Jupiter transaction and validate its signer boundary.
     *
     * This method does not sign, broadcast, or execute the transaction.
     *
     * @return array<string, mixed>
     */
    public function prepare(
        string $wallet,
        string $outputMint,
        string $amount,
        int $slippageBps,
    ): array {
        if (! $this->wallets->isValidAddress($wallet)
            || ! $this->wallets->isValidAddress($outputMint)
            || ! $this->isPositiveInteger($amount)
            || $slippageBps < 0
            || $slippageBps > 10_000) {
            throw new RuntimeException('Invalid swap order parameters.');
        }

        $baseUrl = rtrim(
            (string) config('services.jupiter.swap_v2_base_url'),
            '/'
        );

        if (! str_starts_with($baseUrl, 'https://')) {
            throw new RuntimeException(
                'Jupiter Swap V2 requires HTTPS.'
            );
        }

        try {
            $request = Http::baseUrl($baseUrl)
                ->connectTimeout(3)
                ->timeout(10)
                ->acceptJson();

            $apiKey = trim((string) config('services.jupiter.api_key'));

            if ($apiKey !== '') {
                $request = $request->withHeaders([
                    'x-api-key' => $apiKey,
                ]);
            }

            $response = $request->get('/order', [
                'inputMint' => SolanaSwapQuoteService::SOL_MINT,
                'outputMint' => $outputMint,
                'amount' => $amount,
                'taker' => $wallet,
                'slippageBps' => $slippageBps,
            ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException(
                'A swap order is temporarily unavailable.',
                previous: $exception
            );
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                'A swap order is temporarily unavailable.'
            );
        }

        $data = $response->json();

        $transaction = data_get($data, 'transaction');
        $requestId = data_get($data, 'requestId');

        if (! is_string($transaction)
            || $transaction === ''
            || ! $this->isCanonicalBase64($transaction)
            || ! is_string($requestId)
            || trim($requestId) === '') {
            throw new RuntimeException(
                'The swap order provider returned invalid data.'
            );
        }

        /*
         * Jupiter is an external transaction constructor. Never hand its
         * transaction to the customer's wallet before our independent
         * validator has inspected the signer boundary.
         */
        $validation = $this->validator->inspectPrepared(
            $transaction,
            $wallet
        );

        if (($validation['expected_wallet_is_required_signer'] ?? false) !== true
            || ($validation['expected_wallet_is_fee_payer'] ?? false) !== true
            || ($validation['expected_wallet_signature_present'] ?? true) !== false) {
            throw new RuntimeException(
                'The prepared swap transaction failed validation.'
            );
        }

        return [
            'transaction' => $transaction,
            'request_id' => $requestId,
            'validation' => $validation,
        ];
    }

    private function isPositiveInteger(string $value): bool
    {
        return preg_match('/^[1-9]\d*$/', $value) === 1;
    }

    private function isCanonicalBase64(string $value): bool
    {
        $decoded = base64_decode($value, true);

        return $decoded !== false
            && $decoded !== ''
            && base64_encode($decoded) === $value;
    }
}
