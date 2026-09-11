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
