<?php

namespace App\Services;

use App\Chain;
use App\Models\TokenScan;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SolanaSwapQuoteService
{
    public const SOL_MINT = 'So11111111111111111111111111111111111111112';

    public const LAMPORTS_PER_SOL = 1_000_000_000;

    /** @return array<string, mixed> */
    public function quote(string $outputMint, string $amount, int $slippageBps): array
    {
        try {
            $request = Http::baseUrl(rtrim((string) config('services.jupiter.base_url'), '/'))
                ->connectTimeout(3)
                ->timeout(10)
                ->acceptJson();

            $apiKey = trim((string) config('services.jupiter.api_key'));

            if ($apiKey !== '') {
                $request = $request->withHeaders(['x-api-key' => $apiKey]);
            }

            $response = $request->get('/quote', [
                'inputMint' => self::SOL_MINT,
                'outputMint' => $outputMint,
                'amount' => $amount,
                'slippageBps' => $slippageBps,
                'swapMode' => 'ExactIn',
                'restrictIntermediateTokens' => 'true',
            ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('A swap quote is temporarily unavailable.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException('A swap quote is temporarily unavailable.');
        }

        $data = $response->json();
        $inAmount = data_get($data, 'inAmount');
        $outAmount = data_get($data, 'outAmount');
        $minimumReceived = data_get($data, 'otherAmountThreshold');
        $priceImpact = data_get($data, 'priceImpactPct');
        $routePlan = data_get($data, 'routePlan');

        if (data_get($data, 'inputMint') !== self::SOL_MINT
            || data_get($data, 'outputMint') !== $outputMint
            || data_get($data, 'swapMode') !== 'ExactIn'
            || data_get($data, 'slippageBps') !== $slippageBps
            || $inAmount !== $amount
            || ! $this->isPositiveInteger($outAmount)
            || ! $this->isPositiveInteger($minimumReceived)
            || ! $this->isLessThanOrEqualUnsignedInteger($minimumReceived, $outAmount)
            || ! is_string($priceImpact)
            || preg_match('/^\d+(?:\.\d+)?$/', $priceImpact) !== 1
            || ! is_array($routePlan)
            || $routePlan === []
            || ! collect($routePlan)->every(fn (mixed $step): bool => $this->isValidRouteStep($step))
            || collect($routePlan)->sum('percent') !== 100) {
            throw new RuntimeException('The swap quote provider returned invalid data.');
        }

        $token = TokenScan::query()
            ->where('chain', Chain::Solana->value)
            ->where('address', $outputMint)
            ->latest('last_scanned_at')
            ->first();
        $decimals = data_get($token?->raw_data, 'decimals');
        $decimals = is_int($decimals) && $decimals >= 0 && $decimals <= 18 ? $decimals : null;

        return [
            'input' => ['mint' => self::SOL_MINT, 'amount' => $amount, 'symbol' => 'SOL', 'decimals' => 9],
            'output' => ['mint' => $outputMint, 'amount' => $outAmount, 'symbol' => $token?->symbol, 'decimals' => $decimals],
            'slippage_bps' => $slippageBps,
            'price_impact_pct' => (string) $priceImpact,
            'minimum_received' => $minimumReceived,
            'route' => collect($routePlan)->map(function (mixed $step): array {
                $swap = is_array($step) ? ($step['swapInfo'] ?? []) : [];

                return [
                    'label' => is_string($swap['label'] ?? null) ? $swap['label'] : 'Unknown route',
                    'percent' => is_int($step['percent'] ?? null) ? $step['percent'] : null,
                    'fee_amount' => $this->isUnsignedInteger($swap['feeAmount'] ?? null) ? $swap['feeAmount'] : null,
                    'fee_mint' => is_string($swap['feeMint'] ?? null) ? $swap['feeMint'] : null,
                ];
            })->values()->all(),
        ];
    }

    private function isPositiveInteger(mixed $value): bool
    {
        return $this->isUnsignedInteger($value) && preg_match('/[1-9]/', $value) === 1;
    }

    private function isUnsignedInteger(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d+$/', $value) === 1;
    }

    private function isLessThanOrEqualUnsignedInteger(string $left, string $right): bool
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';

        return strlen($left) < strlen($right)
            || (strlen($left) === strlen($right) && strcmp($left, $right) <= 0);
    }

    private function isValidRouteStep(mixed $step): bool
    {
        if (! is_array($step) || ! is_array($step['swapInfo'] ?? null)) {
            return false;
        }

        $swap = $step['swapInfo'];

        return is_string($swap['label'] ?? null)
            && trim($swap['label']) !== ''
            && is_int($step['percent'] ?? null)
            && $step['percent'] > 0
            && $step['percent'] <= 100
            && $this->isUnsignedInteger($swap['feeAmount'] ?? null)
            && is_string($swap['feeMint'] ?? null);
    }
}
