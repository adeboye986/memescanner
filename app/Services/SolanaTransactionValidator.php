<?php

namespace App\Services;

use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;

class SolanaTransactionValidator
{
    private const MAX_TRANSACTION_BASE64_LENGTH = 4096;

    private const MAX_OUTPUT_BYTES = 16_384;

    /** @return array<string, mixed> */
    public function inspectPrepared(string $transaction, string $expectedWallet): array
    {
        return $this->run([
            'operation' => 'inspect_prepared',
            'transaction' => $this->transaction($transaction),
            'expected_wallet' => $expectedWallet,
        ]);
    }

    /** @return array<string, mixed> */
    public function compareSigned(string $preparedTransaction, string $signedTransaction, string $expectedWallet): array
    {
        return $this->run([
            'operation' => 'compare_signed',
            'prepared_transaction' => $this->transaction($preparedTransaction),
            'signed_transaction' => $this->transaction($signedTransaction),
            'expected_wallet' => $expectedWallet,
        ]);
    }

    /**
     * @param  array<string, string>  $payload
     * @return array<string, mixed>
     */
    private function run(array $payload): array
    {
        try {
            $input = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Solana transaction validation is unavailable.', previous: $exception);
        }

        $process = new Process([
            (string) config('services.solana.transaction_validator_node', 'node'),
            base_path('scripts/solana-transaction-validator.mjs'),
        ], base_path());
        $process->setInput($input);
        $process->setTimeout(max(1, (int) config('services.solana.transaction_validator_timeout_seconds', 5)));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Solana transaction validation is unavailable.');
        }

        $output = $process->getOutput();

        if (strlen($output) > self::MAX_OUTPUT_BYTES) {
            throw new RuntimeException('Solana transaction validation returned invalid data.');
        }

        try {
            $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Solana transaction validation returned invalid data.', previous: $exception);
        }

        if (! $this->isValidResult($result)) {
            throw new RuntimeException('Solana transaction validation returned invalid data.');
        }

        return $result;
    }

    private function transaction(string $transaction): string
    {
        if ($transaction === '' || strlen($transaction) > self::MAX_TRANSACTION_BASE64_LENGTH) {
            throw new RuntimeException('The Solana transaction is invalid.');
        }

        return $transaction;
    }

    private function isValidResult(mixed $result): bool
    {
        if (! is_array($result) || ! is_bool($result['valid'] ?? null)) {
            return false;
        }

        if ($result['valid'] === false) {
            return is_string($result['error_code'] ?? null)
                && preg_match('/^[a-z0-9_]+$/', $result['error_code']) === 1;
        }

        return ($result['transaction_version'] ?? null) === 0
            && is_string($result['message_hash'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/', $result['message_hash']) === 1
            && is_string($result['fee_payer'] ?? null)
            && is_array($result['required_signer_addresses'] ?? null)
            && collect($result['required_signer_addresses'])->every(fn (mixed $address): bool => is_string($address) && $address !== '')
            && is_bool($result['expected_wallet_is_required_signer'] ?? null)
            && is_bool($result['expected_wallet_is_fee_payer'] ?? null)
            && is_int($result['signature_count'] ?? null)
            && $result['signature_count'] > 0
            && is_bool($result['expected_wallet_signature_present'] ?? null)
            && is_string($result['recent_blockhash'] ?? null)
            && $result['recent_blockhash'] !== ''
            && is_array($result['address_lookup_table_references'] ?? null)
            && collect($result['address_lookup_table_references'])->every(fn (mixed $address): bool => is_string($address) && $address !== '');
    }
}
