<?php

namespace App\Services\TradingEngine;

use DateTimeImmutable;
use Throwable;

class TradingEngineScanEventPayloadValidator
{
    /** @param array<string, mixed> $payload */
    public function isValid(array $payload): bool
    {
        $keys = array_keys($payload);
        sort($keys);
        $expected = [
            'completed_at', 'counts', 'error_code', 'network', 'outcome',
            'provider_failures', 'scan_execution_id', 'scanner', 'source', 'subject',
        ];
        sort($expected);

        if ($keys !== $expected
            || ! $this->engineId($payload['scan_execution_id'] ?? null)
            || ! in_array($payload['scanner'] ?? null, ['new-token', 'momentum'], true)
            || ! in_array($payload['outcome'] ?? null, ['succeeded', 'succeeded_with_warnings', 'failed'], true)
            || ! $this->dateTime($payload['completed_at'] ?? null)
            || ! $this->source($payload['source'] ?? null)
            || ! $this->subject($payload['subject'] ?? null)
            || ! $this->network($payload['network'] ?? null)
            || ! $this->counts($payload['counts'] ?? null)
            || ! $this->providerFailures($payload['provider_failures'] ?? null)
            || (! is_null($payload['error_code'] ?? null) && ! $this->code($payload['error_code']))) {
            return false;
        }

        return ($payload['outcome'] === 'failed') === is_string($payload['error_code']);
    }

    private function source(mixed $value): bool
    {
        return is_array($value)
            && array_keys($value) === ['system', 'activity_id']
            && ($value['system'] ?? null) === 'meme-scanner-laravel'
            && is_string($value['activity_id'] ?? null)
            && preg_match('/^[1-9][0-9]{0,63}$/D', $value['activity_id']) === 1;
    }

    private function subject(mixed $value): bool
    {
        return is_array($value)
            && array_keys($value) === ['control_plane_user_id']
            && is_string($value['control_plane_user_id'] ?? null)
            && preg_match('/^[1-9][0-9]{0,63}$/D', $value['control_plane_user_id']) === 1;
    }

    private function network(mixed $value): bool
    {
        return is_array($value)
            && array_keys($value) === ['id']
            && in_array($value['id'] ?? null, [
                'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp',
                'eip155:1',
            ], true);
    }

    private function counts(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        $keys = array_keys($value);
        sort($keys);
        $expected = [
            'discovered', 'examined', 'failed', 'opportunities_created',
            'opportunities_existing', 'qualified', 'skipped',
        ];
        sort($expected);

        if ($keys !== $expected
            || ! collect($value)->every(fn (mixed $count): bool => is_int($count) && $count >= 0)) {
            return false;
        }

        return $value['examined'] <= $value['discovered']
            && $value['qualified'] <= $value['examined']
            && $value['qualified'] >= $value['opportunities_created'] + $value['opportunities_existing']
            && $value['skipped'] <= $value['examined']
            && $value['failed'] <= $value['examined'];
    }

    private function providerFailures(mixed $value): bool
    {
        return is_array($value)
            && array_is_list($value)
            && count($value) <= 32
            && collect($value)->every(fn (mixed $code): bool => $this->code($code));
    }

    private function code(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Z][A-Z0-9_]{0,127}$/D', $value) === 1;
    }

    private function engineId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $value) === 1;
    }

    private function dateTime(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        try {
            new DateTimeImmutable($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
