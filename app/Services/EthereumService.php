<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class EthereumService
{
    public function getBalanceWei(string $address): string
    {
        $url = (string) config('services.ethereum.rpc_url');
        if ($url === '' || ! str_starts_with($url, 'https://')) {
            throw new RuntimeException('Ethereum RPC is not configured securely.');
        }

        try {
            $response = Http::acceptJson()->asJson()->connectTimeout(3)->timeout(8)
                ->post($url, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'eth_getBalance', 'params' => [$address, 'latest']]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Ethereum RPC is unavailable.', previous: $exception);
        }

        $hex = $response->json('result');
        if (! $response->successful() || ! is_string($hex) || preg_match('/^0x[0-9a-fA-F]+$/', $hex) !== 1) {
            throw new RuntimeException('Ethereum RPC returned an invalid balance.');
        }

        return $this->hexToDecimal($hex);
    }

    public function call(string $to, string $data): string
    {
        $rpcUrl = trim((string) config('services.ethereum.rpc_url'));

        if (! str_starts_with($rpcUrl, 'https://')) {
            throw new RuntimeException('Ethereum RPC is not configured securely.');
        }

        if (preg_match('/^0x[a-fA-F0-9]{40}$/', $to) !== 1) {
            throw new RuntimeException('Ethereum contract address is invalid.');
        }

        if (preg_match('/^0x[a-fA-F0-9]*$/', $data) !== 1) {
            throw new RuntimeException('Ethereum call data is invalid.');
        }

        try {
            $response = Http::connectTimeout(3)
                ->timeout(8)
                ->acceptJson()
                ->post($rpcUrl, [
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => 'eth_call',
                    'params' => [
                        [
                            'to' => strtolower($to),
                            'data' => strtolower($data),
                        ],
                        'latest',
                    ],
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException(
                'Ethereum RPC is temporarily unavailable.',
                previous: $exception,
            );
        }

        $result = $response->json('result');

        if (! $response->successful()
            || ! is_string($result)
            || preg_match('/^0x[a-fA-F0-9]*$/', $result) !== 1) {
            throw new RuntimeException('Ethereum RPC returned an invalid contract call response.');
        }

        return strtolower($result);
    }

    public function getTransactionByHash(string $transactionHash): array
    {
        $rpcUrl = trim((string) config('services.ethereum.rpc_url'));

        if (! str_starts_with($rpcUrl, 'https://')) {
            throw new RuntimeException('Ethereum RPC is not configured securely.');
        }

        if (preg_match('/^0x[a-fA-F0-9]{64}$/', $transactionHash) !== 1) {
            throw new RuntimeException('Ethereum transaction hash is invalid.');
        }

        try {
            $response = Http::connectTimeout(3)
                ->timeout(8)
                ->acceptJson()
                ->post($rpcUrl, [
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => 'eth_getTransactionByHash',
                    'params' => [strtolower($transactionHash)],
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException(
                'Ethereum RPC is temporarily unavailable.',
                previous: $exception,
            );
        }

        $result = $response->json('result');

        if (! $response->successful() || ! is_array($result)) {
            throw new RuntimeException('Ethereum transaction could not be verified.');
        }

        foreach (['hash', 'from', 'to', 'value', 'input'] as $field) {
            if (! array_key_exists($field, $result) || ! is_string($result[$field])) {
                throw new RuntimeException('Ethereum RPC returned an invalid transaction response.');
            }
        }

        if (preg_match('/^0x[a-fA-F0-9]{64}$/', $result['hash']) !== 1
            || preg_match('/^0x[a-fA-F0-9]{40}$/', $result['from']) !== 1
            || preg_match('/^0x[a-fA-F0-9]{40}$/', $result['to']) !== 1
            || preg_match('/^0x[0-9a-fA-F]+$/', $result['value']) !== 1
            || preg_match('/^0x[0-9a-fA-F]*$/', $result['input']) !== 1) {
            throw new RuntimeException('Ethereum RPC returned an invalid transaction response.');
        }

        return [
            'hash' => strtolower($result['hash']),
            'from' => strtolower($result['from']),
            'to' => strtolower($result['to']),
            'value' => $this->hexToDecimal($result['value']),
            'input' => strtolower($result['input']),
        ];
    }

    public function getTransactionReceipt(string $transactionHash): ?array
    {
        $rpcUrl = trim((string) config('services.ethereum.rpc_url'));

        if (! str_starts_with($rpcUrl, 'https://')) {
            throw new RuntimeException('Ethereum RPC is not configured securely.');
        }

        if (preg_match('/^0x[a-fA-F0-9]{64}$/', $transactionHash) !== 1) {
            throw new RuntimeException('Ethereum transaction hash is invalid.');
        }

        try {
            $response = Http::connectTimeout(3)
                ->timeout(8)
                ->acceptJson()
                ->post($rpcUrl, [
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => 'eth_getTransactionReceipt',
                    'params' => [strtolower($transactionHash)],
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException(
                'Ethereum RPC is temporarily unavailable.',
                previous: $exception,
            );
        }

        if (! $response->successful()) {
            throw new RuntimeException('Ethereum transaction receipt could not be read.');
        }

        $result = $response->json('result');

        if ($result === null) {
            return null;
        }

        if (! is_array($result)) {
            throw new RuntimeException('Ethereum RPC returned an invalid transaction receipt.');
        }

        foreach (
            [
                'transactionHash',
                'status',
                'blockNumber',
                'gasUsed',
                'effectiveGasPrice',
            ] as $field
        ) {
            if (! array_key_exists($field, $result) || ! is_string($result[$field])) {
                throw new RuntimeException('Ethereum RPC returned an invalid transaction receipt.');
            }
        }

        if (
            preg_match('/^0x[a-fA-F0-9]{64}$/', $result['transactionHash']) !== 1
            || ! in_array(strtolower($result['status']), ['0x0', '0x1'], true)
            || preg_match('/^0x[0-9a-fA-F]+$/', $result['blockNumber']) !== 1
            || preg_match('/^0x[0-9a-fA-F]+$/', $result['gasUsed']) !== 1
            || preg_match('/^0x[0-9a-fA-F]+$/', $result['effectiveGasPrice']) !== 1
        ) {
            throw new RuntimeException('Ethereum RPC returned an invalid transaction receipt.');
        }

        $gasUsed = $this->hexToDecimal($result['gasUsed']);
        $effectiveGasPriceWei = $this->hexToDecimal($result['effectiveGasPrice']);

        return [
            'transaction_hash' => strtolower($result['transactionHash']),
            'succeeded' => strtolower($result['status']) === '0x1',
            'block_number' => $this->hexToDecimal($result['blockNumber']),
            'gas_used' => $gasUsed,
            'effective_gas_price_wei' => $effectiveGasPriceWei,
            'actual_network_fee_wei' => bcmul(
                $gasUsed,
                $effectiveGasPriceWei,
                0,
            ),
        ];
    }

    private function hexToDecimal(string $hex): string
    {
        $value = '0';
        foreach (str_split(substr($hex, 2)) as $digit) {
            $carry = hexdec($digit);
            $next = '';
            for ($index = strlen($value) - 1; $index >= 0; $index--) {
                $carry += ((int) $value[$index]) * 16;
                $next = ($carry % 10).$next;
                $carry = intdiv($carry, 10);
            }
            while ($carry > 0) {
                $next = ($carry % 10).$next;
                $carry = intdiv($carry, 10);
            }
            $value = ltrim($next, '0') ?: '0';
        }

        return $value;
    }
}
