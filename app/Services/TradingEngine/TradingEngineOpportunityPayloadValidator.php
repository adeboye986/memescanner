<?php

namespace App\Services\TradingEngine;

use DateTimeImmutable;
use Throwable;

class TradingEngineOpportunityPayloadValidator
{
    private const BASE58_ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    private const ETHEREUM_MAINNET_ID = 'eip155:1';

    private const SOLANA_MAINNET_ID = 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp';

    /** @param array<string, mixed> $payload */
    public function isValid(array $payload): bool
    {
        if (! $this->hasOnlyKeys($payload, [
            'operation_id', 'opportunity_id', 'schema_version', 'source', 'subject',
            'network', 'asset', 'market_snapshot', 'qualification', 'security',
        ], ['security'])
            || ! $this->engineId($payload['operation_id'] ?? null)
            || ! $this->engineId($payload['opportunity_id'] ?? null)
            || ($payload['schema_version'] ?? null) !== 1
            || ! $this->source($payload['source'] ?? null)
            || ! $this->subject($payload['subject'] ?? null)
            || ! $this->networkAsset($payload['network'] ?? null, $payload['asset'] ?? null)
            || ! $this->market($payload['market_snapshot'] ?? null)
            || ! $this->qualification($payload['qualification'] ?? null)) {
            return false;
        }

        return ! array_key_exists('security', $payload) || $this->security($payload['security']);
    }

    private function source(mixed $value): bool
    {
        return is_array($value)
            && $this->hasOnlyKeys($value, ['system', 'opportunity_id', 'discovery_key', 'scanner'])
            && $value['system'] === 'meme-scanner-laravel'
            && $this->stringLength($value['opportunity_id'], 1, 64)
            && $this->matches($value['discovery_key'], '/^[0-9a-f]{64}$/D')
            && in_array($value['scanner'], ['new-token', 'momentum'], true);
    }

    private function subject(mixed $value): bool
    {
        return is_array($value)
            && $this->hasOnlyKeys($value, ['control_plane_user_id'])
            && $this->stringLength($value['control_plane_user_id'], 1, 64);
    }

    private function networkAsset(mixed $network, mixed $asset): bool
    {
        if (! is_array($network)
            || ! $this->hasOnlyKeys($network, ['id'])
            || ! is_array($asset)
            || ! $this->hasOnlyKeys($asset, ['address', 'symbol', 'name'], ['symbol', 'name'])
            || ! $this->stringLength($asset['address'], 1, 128)
            || (array_key_exists('symbol', $asset) && ! $this->stringLength($asset['symbol'], 1, 64))
            || (array_key_exists('name', $asset) && ! $this->stringLength($asset['name'], 1, 255))) {
            return false;
        }

        return match ($network['id'] ?? null) {
            self::ETHEREUM_MAINNET_ID => $this->matches($asset['address'], '/^0x[0-9a-f]{40}$/D'),
            self::SOLANA_MAINNET_ID => $this->solanaAddress($asset['address']),
            default => false,
        };
    }

    private function market(mixed $value): bool
    {
        if (! is_array($value)
            || ! $this->hasOnlyKeys($value, [
                'price_usd', 'market_cap_usd', 'liquidity_usd', 'volume_usd', 'pair',
            ], ['price_usd', 'market_cap_usd', 'liquidity_usd', 'volume_usd', 'pair'])) {
            return false;
        }

        foreach (['price_usd', 'market_cap_usd', 'liquidity_usd'] as $measurement) {
            if (array_key_exists($measurement, $value) && ! $this->measurement($value[$measurement])) {
                return false;
            }
        }

        if (array_key_exists('volume_usd', $value)) {
            $volume = $value['volume_usd'];

            if (! is_array($volume)
                || ! $this->hasOnlyKeys($volume, ['value', 'provider', 'window'])
                || ! $this->unsignedDecimal($volume['value'])
                || ! in_array($volume['provider'], ['birdeye', 'dexscreener'], true)
                || ! in_array($volume['window'], ['1m', '5m'], true)) {
                return false;
            }
        }

        if (array_key_exists('pair', $value)) {
            $pair = $value['pair'];

            if (! is_array($pair)
                || ! $this->hasOnlyKeys($pair, ['address', 'dex', 'provider'], ['dex'])
                || ! $this->stringLength($pair['address'], 1, 128)
                || ($pair['provider'] ?? null) !== 'dexscreener'
                || (array_key_exists('dex', $pair) && ! $this->stringLength($pair['dex'], 1, 128))) {
                return false;
            }
        }

        return true;
    }

    private function measurement(mixed $value): bool
    {
        return is_array($value)
            && $this->hasOnlyKeys($value, ['value', 'provider'])
            && $this->unsignedDecimal($value['value'])
            && in_array($value['provider'], ['birdeye', 'dexscreener'], true);
    }

    private function qualification(mixed $value): bool
    {
        return is_array($value)
            && $this->hasOnlyKeys($value, [
                'qualified_at', 'discovery_market_cap_usd',
                'move_since_discovery_percent', 'classification',
            ], ['discovery_market_cap_usd', 'move_since_discovery_percent', 'classification'])
            && $this->dateTime($value['qualified_at'] ?? null)
            && (! array_key_exists('discovery_market_cap_usd', $value)
                || $this->unsignedDecimal($value['discovery_market_cap_usd']))
            && (! array_key_exists('move_since_discovery_percent', $value)
                || $this->signedDecimal($value['move_since_discovery_percent']))
            && (! array_key_exists('classification', $value)
                || $this->stringLength($value['classification'], 1, 64));
    }

    private function security(mixed $value): bool
    {
        if (! is_array($value)
            || ! $this->hasOnlyKeys($value, [
                'status', 'provider', 'passed', 'score', 'risks', 'coverage',
                'holder_concentration', 'market_validation', 'unavailable_checks',
            ], [
                'provider', 'passed', 'score', 'risks', 'coverage',
                'holder_concentration', 'market_validation', 'unavailable_checks',
            ])
            || ! in_array($value['status'] ?? null, ['passed', 'failed', 'unavailable'], true)
            || (array_key_exists('provider', $value)
                && ! in_array($value['provider'], ['goplus', 'solana_rpc_holder_analysis'], true))
            || (array_key_exists('passed', $value) && ! is_bool($value['passed']))
            || (array_key_exists('score', $value)
                && (! is_int($value['score']) || $value['score'] < 0 || $value['score'] > 100))
            || (array_key_exists('coverage', $value) && ! $this->stringLength($value['coverage'], 1, 512))
            || (array_key_exists('risks', $value) && ! $this->stringList($value['risks'], 100, 512))
            || (array_key_exists('unavailable_checks', $value)
                && ! $this->stringList($value['unavailable_checks'], 100, 128))) {
            return false;
        }

        if (array_key_exists('holder_concentration', $value)) {
            $holder = $value['holder_concentration'];

            if (! is_array($holder)
                || ! $this->hasOnlyKeys($holder, [
                    'largest_holder_percent', 'top_5_percent', 'top_10_percent', 'risk_level',
                ], ['largest_holder_percent', 'top_5_percent', 'top_10_percent', 'risk_level'])) {
                return false;
            }

            foreach (['largest_holder_percent', 'top_5_percent', 'top_10_percent'] as $field) {
                if (array_key_exists($field, $holder) && ! $this->unsignedDecimal($holder[$field])) {
                    return false;
                }
            }

            if (array_key_exists('risk_level', $holder)
                && ! $this->stringLength($holder['risk_level'], 1, 64)) {
                return false;
            }
        }

        if (array_key_exists('market_validation', $value)) {
            $validation = $value['market_validation'];

            if (! is_array($validation)
                || ! $this->hasOnlyKeys($validation, [
                    'provider', 'requested_token_is_base', 'pair_available',
                ])
                || ($validation['provider'] ?? null) !== 'dexscreener'
                || ! is_bool($validation['requested_token_is_base'] ?? null)
                || ! is_bool($validation['pair_available'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  array<int, string>  $allowed
     * @param  array<int, string>  $optional
     */
    private function hasOnlyKeys(array $value, array $allowed, array $optional = []): bool
    {
        $keys = array_keys($value);
        $required = array_values(array_diff($allowed, $optional));

        return array_diff($keys, $allowed) === [] && array_diff($required, $keys) === [];
    }

    private function unsignedDecimal(mixed $value): bool
    {
        return $this->matches($value, '/^(?:0|[1-9][0-9]{0,47})(?:\.[0-9]{0,29}[1-9])?$/D');
    }

    private function signedDecimal(mixed $value): bool
    {
        return $value !== '-0'
            && $this->matches($value, '/^-?(?:0|[1-9][0-9]{0,47})(?:\.[0-9]{0,29}[1-9])?$/D');
    }

    private function engineId(mixed $value): bool
    {
        return $this->matches($value, '/^[0-9A-HJKMNP-TV-Z]{26}$/D');
    }

    private function stringList(mixed $value, int $maximumItems, int $maximumLength): bool
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > $maximumItems) {
            return false;
        }

        foreach ($value as $item) {
            if (! $this->stringLength($item, 0, $maximumLength)) {
                return false;
            }
        }

        return true;
    }

    private function stringLength(mixed $value, int $minimum, int $maximum): bool
    {
        return is_string($value) && mb_strlen($value) >= $minimum && mb_strlen($value) <= $maximum;
    }

    private function matches(mixed $value, string $pattern): bool
    {
        return is_string($value) && preg_match($pattern, $value) === 1;
    }

    private function solanaAddress(mixed $value): bool
    {
        if (! is_string($value)
            || preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/D', $value) !== 1) {
            return false;
        }

        $bytes = [];

        foreach (str_split($value) as $character) {
            $digit = strpos(self::BASE58_ALPHABET, $character);
            $carry = $digit === false ? 0 : $digit;

            foreach ($bytes as $index => $byte) {
                $carry += $byte * 58;
                $bytes[$index] = $carry & 0xFF;
                $carry = intdiv($carry, 256);
            }

            while ($carry > 0) {
                $bytes[] = $carry & 0xFF;
                $carry = intdiv($carry, 256);
            }
        }

        return strspn($value, '1') + count($bytes) === 32;
    }

    private function dateTime(mixed $value): bool
    {
        if (! is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
            return false;
        }

        try {
            new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();

            return $errors === false
                || ($errors['warning_count'] === 0 && $errors['error_count'] === 0);
        } catch (Throwable) {
            return false;
        }
    }
}
