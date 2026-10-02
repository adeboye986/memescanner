<?php

namespace App\Services\TradingEngine;

class TradingEnginePaperLifecyclePayloadValidator
{
    /** @param array<string, mixed> $payload */
    public function isValidRecorded(array $payload): bool
    {
        return ($payload['schema_version'] ?? null) === 1
            && $this->engineId($payload['operation_id'] ?? null)
            && $this->engineId($payload['position_id'] ?? null)
            && $this->source($payload['source'] ?? null, true)
            && $this->subject($payload['subject'] ?? null)
            && $this->network($payload['network'] ?? null)
            && $this->asset($payload['asset'] ?? null)
            && is_array($payload['entry'] ?? null)
            && is_array($payload['strategy'] ?? null)
            && $this->policy($payload['policy'] ?? null);
    }

    /** @param array<string, mixed> $payload */
    public function isValidEvaluated(array $payload): bool
    {
        return $this->engineId($payload['position_id'] ?? null)
            && $this->engineId($payload['decision_id'] ?? null)
            && $this->source($payload['source'] ?? null, false)
            && $this->subject($payload['subject'] ?? null)
            && $this->network($payload['network'] ?? null)
            && $this->asset($payload['asset'] ?? null)
            && $this->policy($payload['policy'] ?? null)
            && is_int($payload['lifecycle_version'] ?? null)
            && $payload['lifecycle_version'] >= 1
            && in_array($payload['decision'] ?? null, ['HOLD', 'EXIT'], true)
            && $this->decisionSemantics($payload)
            && $this->decimal($payload['observed_multiple'] ?? null)
            && ($payload['trigger_multiple'] === null || $this->decimal($payload['trigger_multiple']))
            && is_array($payload['market'] ?? null)
            && $this->decimal(data_get($payload, 'market.market_cap_usd'))
            && $this->optionalDecimal($payload['market'], 'price_usd')
            && $this->optionalDecimal($payload['market'], 'liquidity_usd')
            && $this->decimal($payload['peak_market_cap_usd'] ?? null)
            && $this->decimal($payload['peak_multiple'] ?? null)
            && $this->decimal($payload['drawdown_percent'] ?? null, true)
            && in_array($payload['protection_before'] ?? null, ['none', 'level_1', 'level_2'], true)
            && in_array($payload['protection_after'] ?? null, ['none', 'level_1', 'level_2'], true)
            && is_array($payload['transitions'] ?? null)
            && array_is_list($payload['transitions'])
            && collect($payload['transitions'])->every(
                fn (mixed $transition): bool => in_array($transition, [
                    'PEAK_UPDATED',
                    'PROTECTION_LEVEL_1_ARMED',
                    'PROTECTION_LEVEL_2_ARMED',
                ], true),
            );
    }

    /** @param array<string, mixed> $payload */
    public function isValidExit(array $payload): bool
    {
        return $this->isValidEvaluated($payload)
            && $payload['decision'] === 'EXIT'
            && $this->engineId($payload['evaluated_event_id'] ?? null)
            && is_string($payload['result_sha256'] ?? null)
            && preg_match('/^[0-9a-f]{64}$/D', $payload['result_sha256']) === 1;
    }

    private function source(mixed $source, bool $registration): bool
    {
        if (! is_array($source)
            || ! is_string($source['paper_position_id'] ?? null)
            || preg_match('/^[1-9][0-9]*$/D', $source['paper_position_id']) !== 1) {
            return false;
        }

        if ($registration) {
            return ($source['system'] ?? null) === 'meme-scanner-laravel'
                && is_string($source['trade_opportunity_id'] ?? null)
                && preg_match('/^[1-9][0-9]*$/D', $source['trade_opportunity_id']) === 1
                && $this->engineId($source['engine_opportunity_id'] ?? null);
        }

        return is_string($source['observation_id'] ?? null)
            && preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $source['observation_id']) === 1
            && is_int($source['sequence'] ?? null)
            && $source['sequence'] >= 1;
    }

    private function subject(mixed $subject): bool
    {
        return is_array($subject)
            && is_string($subject['control_plane_user_id'] ?? null)
            && preg_match('/^[1-9][0-9]*$/D', $subject['control_plane_user_id']) === 1;
    }

    private function network(mixed $network): bool
    {
        return is_array($network)
            && ($network['id'] ?? null) === 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp';
    }

    private function asset(mixed $asset): bool
    {
        return is_array($asset)
            && is_string($asset['address'] ?? null)
            && mb_strlen($asset['address']) >= 32
            && mb_strlen($asset['address']) <= 44;
    }

    private function policy(mixed $policy): bool
    {
        return is_array($policy)
            && ($policy['key'] ?? null) === 'laravel-paper-protection'
            && ($policy['version'] ?? null) === 1;
    }

    /** @param array<string, mixed> $payload */
    private function decisionSemantics(array $payload): bool
    {
        if ($payload['decision'] === 'HOLD') {
            return $payload['exit_type'] === null && $payload['trigger_multiple'] === null;
        }

        return in_array($payload['exit_type'], ['stop_loss', 'protected_floor_exit'], true)
            && $this->decimal($payload['trigger_multiple'] ?? null);
    }

    private function engineId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $value) === 1;
    }

    /** @param array<string, mixed> $values */
    private function optionalDecimal(array $values, string $key): bool
    {
        return ! array_key_exists($key, $values) || $this->decimal($values[$key]);
    }

    private function decimal(mixed $value, bool $signed = false): bool
    {
        return is_string($value)
            && preg_match($signed
                ? '/^-?(?:0|[1-9][0-9]{0,47})(?:\.[0-9]{0,29}[1-9])?$/D'
                : '/^(?:0|[1-9][0-9]{0,47})(?:\.[0-9]{0,29}[1-9])?$/D', $value) === 1;
    }
}
