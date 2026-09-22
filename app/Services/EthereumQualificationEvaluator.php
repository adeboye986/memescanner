<?php

namespace App\Services;

class EthereumQualificationEvaluator
{
    /** @param array<string, mixed> $market */
    public function qualifies(string $scanner, array $market): bool
    {
        if (! ($market['available'] ?? false) || ! ($market['requested_token_is_base'] ?? false)) {
            return false;
        }
        $marketCap = (float) ($market['market_cap'] ?? 0);
        $liquidity = (float) ($market['liquidity_usd'] ?? 0);
        $volume = (float) ($market['volume_5m'] ?? 0);

        if (! is_finite($marketCap) || ! is_finite($liquidity) || ! is_finite($volume)) {
            return false;
        }

        return $scanner === 'new-token'
            ? $marketCap >= 2_000 && $marketCap <= 20_000 && $liquidity >= 500
            : $marketCap >= 5_000 && $marketCap <= 100_000 && $liquidity >= 1_000 && $volume >= 500;
    }
}
