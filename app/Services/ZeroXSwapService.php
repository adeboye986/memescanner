<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ZeroXSwapService
{
    public const NATIVE_ETH = '0xeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    /** @return array<string, mixed> */
    public function price(string $wallet, string $buyToken, string $sellAmount, int $slippageBps): array
    {
        return $this->request('price', $wallet, $buyToken, $sellAmount, $slippageBps, false);
    }

    /** @return array<string, mixed> */
    public function quote(string $wallet, string $buyToken, string $sellAmount, int $slippageBps): array
    {
        return $this->request('quote', $wallet, $buyToken, $sellAmount, $slippageBps, true);
    }

    /** @return array<string, mixed> */
    private function request(string $endpoint, string $wallet, string $buyToken, string $sellAmount, int $slippageBps, bool $firm): array
    {
        $baseUrl = rtrim((string) config('services.zero_x.base_url'), '/');
        $apiKey = trim((string) config('services.zero_x.api_key'));
        if (! str_starts_with($baseUrl, 'https://') || $apiKey === '') {
            throw new RuntimeException('0x Swap API is not configured securely.');
        }

        try {
            $response = Http::baseUrl($baseUrl)->acceptJson()->withHeaders([
                '0x-api-key' => $apiKey,
                '0x-version' => 'v2',
            ])->connectTimeout(3)->timeout(12)->get('/swap/allowance-holder/'.$endpoint, [
                'chainId' => 1,
                'sellToken' => self::NATIVE_ETH,
                'buyToken' => strtolower($buyToken),
                'sellAmount' => $sellAmount,
                'taker' => strtolower($wallet),
                'slippageBps' => $slippageBps,
            ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Ethereum swap pricing is unavailable.', previous: $exception);
        }

        $data = $response->json();
        if (! $response->successful() || ! is_array($data)
            || ($data['liquidityAvailable'] ?? null) !== true
            || strtolower((string) ($data['sellToken'] ?? '')) !== self::NATIVE_ETH
            || strtolower((string) ($data['buyToken'] ?? '')) !== strtolower($buyToken)
            || ($data['sellAmount'] ?? null) !== $sellAmount
            || ! $this->positiveInteger($data['buyAmount'] ?? null)
            || ! $this->positiveInteger($data['minBuyAmount'] ?? null)) {
            throw new RuntimeException('0x returned an invalid Ethereum swap response.');
        }

        $normalized = [
            'sell_amount' => $sellAmount,
            'buy_amount' => $data['buyAmount'],
            'minimum_buy_amount' => $data['minBuyAmount'],
            'network_fee_wei' => $this->unsignedInteger($data['totalNetworkFee'] ?? null) ? $data['totalNetworkFee'] : null,
            'sources' => collect(data_get($data, 'route.fills', []))->pluck('source')->filter()->unique()->values()->all(),
        ];

        if (! $firm) return $normalized;

        $transaction = $data['transaction'] ?? null;
        if (! is_array($transaction)
            || ! $this->address($transaction['to'] ?? null)
            || ! is_string($transaction['data'] ?? null) || preg_match('/^0x[0-9a-fA-F]*$/', $transaction['data']) !== 1
            || ($transaction['value'] ?? null) !== $sellAmount
            || ! $this->positiveInteger($transaction['gas'] ?? null)
            || ! $this->positiveInteger($transaction['gasPrice'] ?? null)) {
            throw new RuntimeException('0x returned an unsafe Ethereum transaction.');
        }

        return [...$normalized, 'quote_id' => is_string($data['zid'] ?? null) ? $data['zid'] : null, 'transaction' => [
            'from' => strtolower($wallet),
            'to' => strtolower($transaction['to']),
            'data' => $transaction['data'],
            'value' => $transaction['value'],
            'gas' => $transaction['gas'],
            'gasPrice' => $transaction['gasPrice'],
            'chainId' => '1',
        ]];
    }

    private function address(mixed $value): bool { return is_string($value) && preg_match('/^0x[a-fA-F0-9]{40}$/', $value) === 1; }
    private function unsignedInteger(mixed $value): bool { return is_string($value) && preg_match('/^\d+$/', $value) === 1; }
    private function positiveInteger(mixed $value): bool { return $this->unsignedInteger($value) && preg_match('/[1-9]/', $value) === 1; }
}
