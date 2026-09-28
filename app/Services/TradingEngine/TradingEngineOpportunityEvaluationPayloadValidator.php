<?php

namespace App\Services\TradingEngine;

class TradingEngineOpportunityEvaluationPayloadValidator
{
    private const ADVISORY_ORDER = [
        'SECURITY_EVIDENCE_UNAVAILABLE',
    ];

    private const CHECKS_BY_PROFILE = [
        'solana:new-token' => [
            'market_cap', 'liquidity', 'movement', 'classification', 'security',
        ],
        'solana:momentum' => [
            'market_cap', 'liquidity', 'volume_5m', 'movement', 'pair_validation', 'security',
        ],
        'ethereum:new-token' => [
            'market_cap', 'liquidity', 'movement', 'pair_validation', 'security',
        ],
        'ethereum:momentum' => [
            'market_cap', 'liquidity', 'volume_5m', 'movement', 'pair_validation', 'security',
        ],
    ];

    private const REASON_ORDER = [
        'MARKET_CAP_MISSING',
        'MARKET_CAP_BELOW_MINIMUM',
        'MARKET_CAP_ABOVE_MAXIMUM',
        'LIQUIDITY_MISSING',
        'LIQUIDITY_BELOW_MINIMUM',
        'VOLUME_5M_MISSING',
        'VOLUME_5M_BELOW_MINIMUM',
        'MOVEMENT_MISSING',
        'MOVEMENT_AT_OR_BELOW_MINIMUM',
        'MOVEMENT_ABOVE_MAXIMUM',
        'CLASSIFICATION_MISSING',
        'CLASSIFICATION_NOT_STRONG',
        'PAIR_EVIDENCE_MISSING',
        'PAIR_VALIDATION_FAILED',
        'SECURITY_EVIDENCE_MISSING',
        'SECURITY_EVIDENCE_FAILED',
        'SECURITY_EVIDENCE_CONTRADICTORY',
    ];

    /** @param  array<string, mixed>  $payload */
    public function isValid(array $payload): bool
    {
        return $this->hasExactKeys($payload, [
            'evaluation_id', 'opportunity_id', 'policy', 'source', 'outcome',
            'reason_codes', 'advisory_codes', 'evidence', 'result_sha256',
        ])
            && $this->engineId($payload['evaluation_id'] ?? null)
            && $this->engineId($payload['opportunity_id'] ?? null)
            && $this->policy($payload['policy'] ?? null)
            && $this->source($payload['source'] ?? null)
            && in_array($payload['outcome'] ?? null, ['passed', 'failed', 'indeterminate'], true)
            && $this->orderedCodes($payload['reason_codes'] ?? null, self::REASON_ORDER)
            && $this->orderedCodes($payload['advisory_codes'] ?? null, self::ADVISORY_ORDER)
            && $this->evidence($payload['evidence'] ?? null)
            && $this->hash($payload['result_sha256'] ?? null);
    }

    private function policy(mixed $value): bool
    {
        return is_array($value)
            && $this->hasExactKeys($value, [
                'key', 'version', 'algorithm_key', 'algorithm_version', 'definition_sha256',
            ])
            && $value['key'] === 'migration-opportunity-snapshot'
            && $value['version'] === 1
            && $value['algorithm_key'] === 'threshold-matrix'
            && $value['algorithm_version'] === 1
            && $this->hash($value['definition_sha256']);
    }

    private function source(mixed $value): bool
    {
        return is_array($value)
            && $this->hasExactKeys($value, ['request_sha256', 'evaluation_input_sha256'])
            && $this->hash($value['request_sha256'])
            && $this->hash($value['evaluation_input_sha256']);
    }

    private function evidence(mixed $value): bool
    {
        if (! is_array($value)
            || ! $this->hasExactKeys($value, ['profile', 'facts', 'checks'])
            || ! is_string($value['profile'] ?? null)
            || ! array_key_exists($value['profile'], self::CHECKS_BY_PROFILE)
            || ! $this->facts($value['facts'] ?? null)
            || ! is_array($value['checks'] ?? null)
            || ! array_is_list($value['checks'])) {
            return false;
        }

        $checks = [];

        foreach ($value['checks'] as $check) {
            if (! is_array($check)
                || ! $this->hasExactKeys($check, ['check', 'status'])
                || ! is_string($check['check'] ?? null)
                || ! in_array($check['status'] ?? null, ['passed', 'failed', 'indeterminate'], true)) {
                return false;
            }

            $checks[] = $check['check'];
        }

        return $checks === self::CHECKS_BY_PROFILE[$value['profile']];
    }

    private function facts(mixed $value): bool
    {
        if (! is_array($value)
            || ! $this->hasExactKeys($value, [
                'market_cap_usd', 'liquidity_usd', 'volume_5m_usd',
                'move_since_discovery_percent', 'classification', 'pair_address',
                'pair_available', 'requested_token_is_base', 'security_status',
                'security_provider', 'security_passed',
            ])) {
            return false;
        }

        foreach ([
            'market_cap_usd',
            'liquidity_usd',
            'volume_5m_usd',
            'move_since_discovery_percent',
        ] as $decimal) {
            if (! is_null($value[$decimal]) && ! $this->decimal($value[$decimal])) {
                return false;
            }
        }

        return (is_null($value['classification']) || $this->stringLength($value['classification'], 1, 64))
            && (is_null($value['pair_address']) || $this->stringLength($value['pair_address'], 1, 128))
            && (is_null($value['pair_available']) || is_bool($value['pair_available']))
            && (is_null($value['requested_token_is_base']) || is_bool($value['requested_token_is_base']))
            && (is_null($value['security_status'])
                || in_array($value['security_status'], ['passed', 'failed', 'unavailable'], true))
            && (is_null($value['security_provider'])
                || in_array($value['security_provider'], ['goplus', 'solana_rpc_holder_analysis'], true))
            && (is_null($value['security_passed']) || is_bool($value['security_passed']));
    }

    /**
     * @param  array<int, string>  $order
     */
    private function orderedCodes(mixed $value, array $order): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }

        $lastPosition = -1;

        foreach ($value as $code) {
            $position = array_search($code, $order, true);

            if ($position === false || $position <= $lastPosition) {
                return false;
            }

            $lastPosition = $position;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  array<int, string>  $keys
     */
    private function hasExactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);

        return $actual === $keys;
    }

    private function decimal(mixed $value): bool
    {
        return is_string($value)
            && $value !== '-0'
            && preg_match('/^-?(?:0|[1-9][0-9]{0,47})(?:\.[0-9]{0,29}[1-9])?$/D', $value) === 1;
    }

    private function engineId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $value) === 1;
    }

    private function hash(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{64}$/D', $value) === 1;
    }

    private function stringLength(mixed $value, int $minimum, int $maximum): bool
    {
        return is_string($value) && mb_strlen($value) >= $minimum && mb_strlen($value) <= $maximum;
    }
}
