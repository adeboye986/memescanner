<?php

namespace App\Services;

use UnexpectedValueException;

class EthereumLiveMarketValidation
{
    /** JSON numbers and unsigned decimal strings only; never coerce unknown evidence. */
    public static function number(mixed $value): float
    {
        if ((! is_int($value) && ! is_float($value) && ! (is_string($value) && preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $value)))
            || ! is_finite($number = (float) $value) || $number < 0) {
            throw new UnexpectedValueException('Unusable Ethereum market metric.');
        }

        return $number;
    }

    public static function address(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^0x[0-9a-fA-F]{40}$/D', $value) ? strtolower($value) : null;
    }

    /** @param array<mixed> $pairs
     * @return list<array<string, mixed>>
     */
    public static function eligiblePairs(array $pairs, string $address): array
    {
        $eligible = [];
        foreach ($pairs as $pair) {
            if (! is_array($pair)) {
                throw new UnexpectedValueException('Unusable Ethereum pool response.');
            }
            if (($pair['chainId'] ?? null) !== 'ethereum' || self::address(data_get($pair, 'baseToken.address')) !== strtolower($address)) {
                continue;
            }
            self::number(data_get($pair, 'liquidity.usd'));
            $eligible[] = $pair;
        }

        return $eligible;
    }

    /** @param array<string, mixed> $market
     * @return array{market_cap: float, liquidity_usd: float, volume_5m: ?float, pair_address: ?string}
     */
    public static function facts(array $market, string $scanner): array
    {
        return ['market_cap' => self::number(data_get($market, 'raw.marketCap')),
            'liquidity_usd' => self::number(data_get($market, 'raw.liquidity.usd')),
            'volume_5m' => $scanner === 'momentum' ? self::number(data_get($market, 'raw.volume.m5')) : null,
            'pair_address' => self::address(data_get($market, 'raw.pairAddress'))];
    }
}
