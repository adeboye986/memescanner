<?php

namespace App\Services\TradingEngine;

class TradingEnginePaperFinancialLifecyclePayloadValidator
{
    private const BASE_KEYS = [
        'operation_id', 'position_id', 'decision_id', 'source', 'subject', 'network', 'asset', 'policy',
        'lifecycle_version', 'decision', 'exit_type', 'market', 'observed_multiple', 'trigger_multiple',
        'peak_market_cap_usd', 'peak_multiple', 'drawdown_percent', 'protection_before',
        'protection_after', 'transitions',
    ];

    /** @param array<string, mixed> $payload */
    public function isValidHeld(array $payload): bool
    {
        return $this->exactKeys($payload, self::BASE_KEYS)
            && $this->validBase($payload)
            && $payload['decision'] === 'HOLD'
            && $payload['exit_type'] === null
            && ! array_key_exists('wallet', $payload)
            && ! array_key_exists('settlement', $payload);
    }

    /** @param array<string, mixed> $payload */
    public function isValidSettled(array $payload): bool
    {
        if (! $this->exactKeys($payload, [...self::BASE_KEYS, 'wallet', 'settlement'])
            || ! $this->validBase($payload)
            || $payload['decision'] !== 'EXIT'
            || ! in_array($payload['exit_type'], ['stop_loss', 'protected_floor_exit'], true)
            || ! $this->objectHasExactKeys($payload['wallet'] ?? null, [
                'wallet_id', 'currency', 'available_balance_native', 'invested_balance_native', 'realized_pnl_native',
            ])
            || ! $this->objectHasExactKeys($payload['settlement'] ?? null, [
                'settlement_id', 'order_id', 'fill_id', 'ledger_transaction_id', 'ledger_transaction_reference',
                'cost_basis_native', 'proceeds_native', 'realized_pnl_native', 'exit_price_usd',
                'exit_market_cap_usd', 'observed_multiple', 'fill_model', 'settled_at',
            ])) {
            return false;
        }

        $wallet = $payload['wallet'];
        $settlement = $payload['settlement'];

        return $this->engineId($wallet['wallet_id'])
            && $wallet['currency'] === 'SOL'
            && $this->decimal($wallet['available_balance_native'])
            && $this->decimal($wallet['invested_balance_native'])
            && $this->decimal($wallet['realized_pnl_native'], true)
            && $this->engineId($settlement['settlement_id'])
            && $this->engineId($settlement['order_id'])
            && $this->engineId($settlement['fill_id'])
            && $this->engineId($settlement['ledger_transaction_id'])
            && is_string($settlement['ledger_transaction_reference'])
            && $this->decimal($settlement['cost_basis_native'])
            && $this->decimal($settlement['proceeds_native'])
            && $this->decimal($settlement['realized_pnl_native'], true)
            && $this->decimal($settlement['exit_price_usd'])
            && $this->decimal($settlement['exit_market_cap_usd'])
            && $this->decimal($settlement['observed_multiple'])
            && $settlement['fill_model'] === 'observed_market_cap_ratio_v1'
            && $this->dateTime($settlement['settled_at']);
    }

    /** @param array<string, mixed> $payload */
    private function validBase(array $payload): bool
    {
        if (! $this->engineId($payload['operation_id'])
            || ! $this->engineId($payload['position_id'])
            || ! $this->engineId($payload['decision_id'])
            || ! is_int($payload['lifecycle_version']) || $payload['lifecycle_version'] < 1
            || ! $this->objectHasExactKeys($payload['source'], ['system', 'observation_id', 'sequence'])
            || $payload['source']['system'] !== 'meme-scanner-laravel'
            || ! is_string($payload['source']['observation_id'])
            || ! is_int($payload['source']['sequence']) || $payload['source']['sequence'] < 1
            || ! $this->objectHasExactKeys($payload['subject'], ['control_plane_user_id'])
            || ! is_string($payload['subject']['control_plane_user_id'])
            || preg_match('/^[1-9][0-9]*$/D', $payload['subject']['control_plane_user_id']) !== 1
            || ! $this->objectHasExactKeys($payload['network'], ['id'])
            || $payload['network']['id'] !== 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp'
            || ! $this->objectHasExactKeys($payload['asset'], ['address'])
            || ! is_string($payload['asset']['address'])
            || mb_strlen($payload['asset']['address']) < 32
            || mb_strlen($payload['asset']['address']) > 44
            || ! $this->objectHasExactKeys($payload['policy'], ['key', 'version'])
            || $payload['policy']['key'] !== 'laravel-paper-protection'
            || $payload['policy']['version'] !== 1
            || ! $this->objectHasExactKeys($payload['market'], array_key_exists('liquidity_usd', $payload['market'])
                ? ['market_cap_usd', 'price_usd', 'liquidity_usd', 'observed_at', 'fetched_at', 'provider']
                : ['market_cap_usd', 'price_usd', 'observed_at', 'fetched_at', 'provider'])
            || ! $this->decimal($payload['market']['market_cap_usd'])
            || ! $this->decimal($payload['market']['price_usd'])
            || (array_key_exists('liquidity_usd', $payload['market']) && ! $this->decimal($payload['market']['liquidity_usd']))
            || ! $this->dateTime($payload['market']['observed_at'])
            || ! $this->dateTime($payload['market']['fetched_at'])
            || ! is_string($payload['market']['provider'])
            || mb_strlen($payload['market']['provider']) < 1
            || mb_strlen($payload['market']['provider']) > 64
            || ! $this->decisionSemantics($payload)
            || ! $this->decimal($payload['observed_multiple'])
            || ! $this->decimal($payload['peak_market_cap_usd'])
            || ! $this->decimal($payload['peak_multiple'])
            || ! $this->decimal($payload['drawdown_percent'], true)
            || ! in_array($payload['protection_before'], ['none', 'level_1', 'level_2'], true)
            || ! in_array($payload['protection_after'], ['none', 'level_1', 'level_2'], true)
            || ! is_array($payload['transitions'])
            || ! array_is_list($payload['transitions'])
            || array_filter(
                $payload['transitions'],
                fn (mixed $transition): bool => ! in_array($transition, [
                    'PEAK_UPDATED', 'PROTECTION_LEVEL_1_ARMED', 'PROTECTION_LEVEL_2_ARMED',
                ], true),
            ) !== []) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $payload */
    private function decisionSemantics(array $payload): bool
    {
        if ($payload['decision'] === 'HOLD') {
            return $payload['exit_type'] === null && $payload['trigger_multiple'] === null;
        }

        return $payload['decision'] === 'EXIT'
            && in_array($payload['exit_type'], ['stop_loss', 'protected_floor_exit'], true)
            && $this->decimal($payload['trigger_multiple']);
    }

    private function engineId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $value) === 1;
    }

    private function decimal(mixed $value, bool $signed = false): bool
    {
        $pattern = $signed ? '/^-?(?:0|[1-9][0-9]{0,47})(?:\.[0-9]{0,29}[1-9])?$/D' : '/^(?:0|[1-9][0-9]{0,47})(?:\.[0-9]{0,29}[1-9])?$/D';

        return is_string($value) && preg_match($pattern, $value) === 1;
    }

    private function dateTime(mixed $value): bool
    {
        return is_string($value) && strtotime($value) !== false;
    }

    /** @param list<string> $keys */
    private function objectHasExactKeys(mixed $value, array $keys): bool
    {
        return is_array($value) && ! array_is_list($value) && $this->exactKeys($value, $keys);
    }

    /** @param array<string, mixed> $value @param list<string> $keys */
    private function exactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);

        return $actual === $keys;
    }
}
