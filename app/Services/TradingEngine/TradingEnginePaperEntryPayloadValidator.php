<?php

namespace App\Services\TradingEngine;

use DateTimeImmutable;
use Throwable;

class TradingEnginePaperEntryPayloadValidator
{
    /** @param array<string, mixed> $payload */
    public function isValid(array $payload): bool
    {
        if (! $this->exactKeys($payload, [
            'operation_id', 'wallet', 'intent', 'order', 'fill', 'position',
            'source', 'subject', 'network', 'asset', 'authority', 'ledger',
        ])) {
            return false;
        }

        $wallet = $this->object($payload['wallet']);
        $intent = $this->object($payload['intent']);
        $order = $this->object($payload['order']);
        $fill = $this->object($payload['fill']);
        $position = $this->object($payload['position']);
        $source = $this->object($payload['source']);
        $subject = $this->object($payload['subject']);
        $network = $this->object($payload['network']);
        $asset = $this->object($payload['asset']);
        $authority = $this->object($payload['authority']);
        $ledger = $this->object($payload['ledger']);

        return $wallet !== null && $intent !== null && $order !== null && $fill !== null
            && $position !== null && $source !== null && $subject !== null && $network !== null
            && $asset !== null && $authority !== null && $ledger !== null
            && $this->exactKeys($wallet, ['wallet_id', 'currency', 'opening_balance_native', 'available_balance_native', 'invested_balance_native'])
            && $this->exactKeys($intent, ['intent_id', 'intent_sha256', 'authority_sha256', 'notional_native'])
            && $this->exactKeys($order, ['order_id', 'side', 'order_type', 'status'])
            && $this->exactKeys($fill, ['fill_id', 'notional_native', 'fee_native', 'fill_price_usd', 'quantity', 'quantity_unit', 'fill_model', 'executed_at'])
            && $this->exactKeys($position, array_key_exists('entry_liquidity_usd', $position)
                ? ['position_id', 'state', 'cost_basis_native', 'entry_market_cap_usd', 'entry_price_usd', 'entry_liquidity_usd', 'lifecycle']
                : ['position_id', 'state', 'cost_basis_native', 'entry_market_cap_usd', 'entry_price_usd', 'lifecycle'])
            && $this->exactKeys($source, ['system', 'trade_opportunity_id', 'engine_opportunity_id', 'evaluation_id', 'evaluation_result_sha256'])
            && $this->exactKeys($subject, ['control_plane_user_id'])
            && $this->exactKeys($network, ['id', 'native_currency'])
            && $this->exactKeys($asset, array_key_exists('symbol', $asset) ? ['address', 'symbol'] : ['address'])
            && $this->exactKeys($ledger, ['opening_transaction_reference', 'entry_transaction_id', 'entry_transaction_reference'])
            && $this->engineId($payload['operation_id'])
            && $this->engineId($wallet['wallet_id'] ?? null)
            && ($wallet['currency'] ?? null) === 'SOL'
            && $this->decimal($wallet['opening_balance_native'] ?? null, true)
            && $this->decimal($wallet['available_balance_native'] ?? null)
            && $this->decimal($wallet['invested_balance_native'] ?? null)
            && $this->engineId($intent['intent_id'] ?? null)
            && $this->hash($intent['intent_sha256'] ?? null)
            && $this->hash($intent['authority_sha256'] ?? null)
            && $this->decimal($intent['notional_native'] ?? null, true)
            && $this->engineId($order['order_id'] ?? null)
            && ($order['side'] ?? null) === 'buy'
            && ($order['order_type'] ?? null) === 'simulated_market'
            && ($order['status'] ?? null) === 'filled'
            && $this->engineId($fill['fill_id'] ?? null)
            && $this->decimal($fill['notional_native'] ?? null, true)
            && $this->decimal($fill['fee_native'] ?? null)
            && $this->decimal($fill['fill_price_usd'] ?? null, true)
            && $this->decimal($fill['quantity'] ?? null, true)
            && ($fill['quantity_unit'] ?? null) === 'normalized_position_unit'
            && ($fill['fill_model'] ?? null) === 'observed_mark_normalized_notional_v1'
            && $this->dateTime($fill['executed_at'] ?? null)
            && $this->engineId($position['position_id'] ?? null)
            && ($position['state'] ?? null) === 'open'
            && $this->decimal($position['cost_basis_native'] ?? null, true)
            && $this->decimal($position['entry_market_cap_usd'] ?? null, true)
            && $this->decimal($position['entry_price_usd'] ?? null, true)
            && (! array_key_exists('entry_liquidity_usd', $position) || $this->decimal($position['entry_liquidity_usd']))
            && $this->validLifecycle($position['lifecycle'] ?? null)
            && ($source['system'] ?? null) === 'meme-scanner-laravel'
            && $this->positiveId($source['trade_opportunity_id'] ?? null)
            && $this->engineId($source['engine_opportunity_id'] ?? null)
            && $this->engineId($source['evaluation_id'] ?? null)
            && $this->hash($source['evaluation_result_sha256'] ?? null)
            && $this->positiveId($subject['control_plane_user_id'] ?? null)
            && ($network['id'] ?? null) === 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp'
            && ($network['native_currency'] ?? null) === 'SOL'
            && is_string($asset['address'] ?? null)
            && mb_strlen($asset['address']) >= 32
            && mb_strlen($asset['address']) <= 44
            && $this->validAuthority($authority)
            && is_string($ledger['opening_transaction_reference'] ?? null)
            && $this->engineId($ledger['entry_transaction_id'] ?? null)
            && is_string($ledger['entry_transaction_reference'] ?? null);
    }

    /** @param array<string, mixed> $authority */
    private function validAuthority(array $authority): bool
    {
        $strategy = $this->object($authority['strategy'] ?? null);
        $risk = $this->object($authority['risk'] ?? null);
        $policy = $this->object($authority['effective_policy'] ?? null);

        return $this->exactKeys($authority, [
            'execution_mode', 'entry_mode', 'trading_enabled', 'preference_version',
            'kill_switch_engaged', 'kill_switch_version', 'strategy', 'risk', 'effective_policy',
        ])
            && $strategy !== null
            && $this->exactKeys($strategy, ['stop_loss_percent', 'protection_level_1_percent', 'protection_level_2_percent'])
            && $risk !== null
            && $this->exactKeys($risk, ['trade_size_native', 'source'])
            && $policy !== null
            && $this->exactKeys($policy, ['key', 'version'])
            && ($authority['execution_mode'] ?? null) === 'paper'
            && ($authority['entry_mode'] ?? null) === 'auto'
            && ($authority['trading_enabled'] ?? null) === true
            && is_string($authority['preference_version'] ?? null)
            && ($authority['kill_switch_engaged'] ?? null) === false
            && is_string($authority['kill_switch_version'] ?? null)
            && $this->decimal($strategy['stop_loss_percent'] ?? null, true)
            && $this->decimal($strategy['protection_level_1_percent'] ?? null, true)
            && $this->decimal($strategy['protection_level_2_percent'] ?? null, true)
            && $this->decimal($risk['trade_size_native'] ?? null, true)
            && ($risk['source'] ?? null) === 'laravel-control-plane'
            && ($policy['key'] ?? null) === 'engine-paper-entry'
            && ($policy['version'] ?? null) === 1;
    }

    private function validLifecycle(mixed $value): bool
    {
        $lifecycle = $this->object($value);

        return $lifecycle !== null
            && $this->exactKeys($lifecycle, ['policy_key', 'policy_version', 'lifecycle_version', 'state'])
            && ($lifecycle['policy_key'] ?? null) === 'laravel-paper-protection'
            && ($lifecycle['policy_version'] ?? null) === 1
            && ($lifecycle['lifecycle_version'] ?? null) === 0
            && ($lifecycle['state'] ?? null) === 'open';
    }

    /** @return array<string, mixed>|null */
    private function object(mixed $value): ?array
    {
        return is_array($value) && ! array_is_list($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $keys
     */
    private function exactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);

        return $actual === $keys;
    }

    private function engineId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $value) === 1;
    }

    private function positiveId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1;
    }

    private function hash(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{64}$/D', $value) === 1;
    }

    private function decimal(mixed $value, bool $positive = false): bool
    {
        return is_string($value)
            && preg_match('/^(?:0|[1-9][0-9]{0,47})(?:\.[0-9]{0,29}[1-9])?$/D', $value) === 1
            && (! $positive || $value !== '0');
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
