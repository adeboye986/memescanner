<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CryptoPriceService
{
    private const MAX_FUTURE_CLOCK_SKEW_SECONDS = 30;

    public function solUsdPrice(): string
    {
        try {
            $request = Http::baseUrl(rtrim((string) config('services.coingecko.base_url'), '/'))
                ->connectTimeout(3)
                ->timeout(8)
                ->acceptJson();

            $apiKey = trim((string) config('services.coingecko.api_key'));

            if ($apiKey !== '') {
                $request = $request->withHeaders(['x-cg-demo-api-key' => $apiKey]);
            }

            $response = $request->get('/simple/price', [
                'ids' => 'solana',
                'vs_currencies' => 'usd',
                'include_last_updated_at' => 'true',
            ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('SOL market price is temporarily unavailable.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException('SOL market price is temporarily unavailable.');
        }

        $lastUpdatedAt = $response->json('solana.last_updated_at');
        $now = now()->timestamp;
        $maximumAge = max(1, (int) config('services.coingecko.max_price_age_seconds', 120));

        if (! is_int($lastUpdatedAt)
            || $lastUpdatedAt <= 0
            || $lastUpdatedAt > $now + self::MAX_FUTURE_CLOCK_SKEW_SECONDS
            || $now - $lastUpdatedAt > $maximumAge) {
            throw new RuntimeException('SOL market price provider returned invalid data.');
        }

        return $this->normalizePositiveDecimal($response->json('solana.usd'));
    }

    public function usdForLamports(int $lamports, string $solUsdPrice): string
    {
        if ($lamports < 0) {
            throw new RuntimeException('Wallet balance cannot be negative.');
        }

        $price = $this->normalizePositiveDecimal($solUsdPrice);

        if (function_exists('bcmul')) {
            $usd = bcdiv(bcmul((string) $lamports, $price, 12), '1000000000', 3);

            return bcdiv(bcadd($usd, '0.005', 3), '1', 2);
        }

        [$whole, $fraction] = array_pad(explode('.', $price, 2), 2, '');
        $priceDigits = ltrim($whole.$fraction, '0') ?: '0';
        $product = $this->multiplyUnsignedIntegers((string) $lamports, $priceDigits);

        return $this->formatRoundedDecimal($product, 9 + strlen($fraction), 2);
    }

    public function ethUsdPrice(): string
    {
        try {
            $request = Http::baseUrl(rtrim((string) config('services.coingecko.base_url'), '/'))
                ->connectTimeout(3)->timeout(8)->acceptJson();
            $apiKey = trim((string) config('services.coingecko.api_key'));
            if ($apiKey !== '') {
                $request = $request->withHeaders(['x-cg-demo-api-key' => $apiKey]);
            }
            $response = $request->get('/simple/price', [
                    'ids' => 'ethereum',
                    'vs_currencies' => 'usd',
                    'include_last_updated_at' => 'true',
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('ETH market price is temporarily unavailable.', previous: $exception);
        }

        $lastUpdatedAt = $response->json('ethereum.last_updated_at');
        $now = now()->timestamp;
        $maximumAge = max(1, (int) config('services.coingecko.max_price_age_seconds', 120));
        if (! $response->successful() || ! is_int($lastUpdatedAt) || $lastUpdatedAt <= 0
            || $lastUpdatedAt > $now + self::MAX_FUTURE_CLOCK_SKEW_SECONDS
            || $now - $lastUpdatedAt > $maximumAge) {
            throw new RuntimeException('ETH market price provider returned invalid data.');
        }

        return $this->normalizePositiveDecimal($response->json('ethereum.usd'));
    }

    public function usdForWei(string $wei, string $ethUsdPrice): string
    {
        if (preg_match('/^\d+$/', $wei) !== 1) {
            throw new RuntimeException('Wallet balance is invalid.');
        }
        $price = $this->normalizePositiveDecimal($ethUsdPrice);
        [$whole, $fraction] = array_pad(explode('.', $price, 2), 2, '');
        $product = $this->multiplyUnsignedIntegers($wei, ltrim($whole.$fraction, '0') ?: '0');

        return $this->formatRoundedDecimal($product, 18 + strlen($fraction), 2);
    }

    private function normalizePositiveDecimal(mixed $value): string
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            throw new RuntimeException('SOL market price provider returned invalid data.');
        }

        $decimal = is_float($value)
            ? rtrim(rtrim(number_format($value, 12, '.', ''), '0'), '.')
            : trim((string) $value);

        if (preg_match('/^\d+(?:\.\d+)?$/', $decimal) !== 1 || preg_match('/[1-9]/', $decimal) !== 1) {
            throw new RuntimeException('SOL market price provider returned invalid data.');
        }

        return $decimal;
    }

    private function multiplyUnsignedIntegers(string $left, string $right): string
    {
        $digits = array_fill(0, strlen($left) + strlen($right), 0);

        for ($leftIndex = strlen($left) - 1; $leftIndex >= 0; $leftIndex--) {
            for ($rightIndex = strlen($right) - 1; $rightIndex >= 0; $rightIndex--) {
                $position = $leftIndex + $rightIndex + 1;
                $total = $digits[$position] + ((int) $left[$leftIndex] * (int) $right[$rightIndex]);
                $digits[$position] = $total % 10;
                $digits[$position - 1] += intdiv($total, 10);
            }
        }

        return ltrim(implode('', $digits), '0') ?: '0';
    }

    private function formatRoundedDecimal(string $digits, int $scale, int $displayScale): string
    {
        if ($scale > $displayScale) {
            $discardedDigits = $scale - $displayScale;
            $digits = str_pad($digits, $discardedDigits + 1, '0', STR_PAD_LEFT);
            $roundUp = (int) $digits[-$discardedDigits] >= 5;
            $digits = substr($digits, 0, -$discardedDigits);

            if ($roundUp) {
                $digits = $this->incrementUnsignedInteger($digits);
            }
        } else {
            $digits .= str_repeat('0', $displayScale - $scale);
        }

        $digits = str_pad($digits, $displayScale + 1, '0', STR_PAD_LEFT);

        return substr($digits, 0, -$displayScale).'.'.substr($digits, -$displayScale);
    }

    private function incrementUnsignedInteger(string $digits): string
    {
        for ($index = strlen($digits) - 1; $index >= 0; $index--) {
            if ($digits[$index] !== '9') {
                $digits[$index] = (string) ((int) $digits[$index] + 1);

                return $digits;
            }

            $digits[$index] = '0';
        }

        return '1'.$digits;
    }
}
