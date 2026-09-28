<?php

namespace App\Services\TradingEngine;

use InvalidArgumentException;

class CanonicalDecimal
{
    public function normalize(mixed $value, bool $allowNegative = false): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_float($value) && ! is_finite($value)) {
            throw new InvalidArgumentException('A finite decimal value is required.');
        }

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw new InvalidArgumentException('A scalar decimal value is required.');
        }

        $expanded = $this->expandScientificNotation((string) $value);

        if (preg_match('/^([+-]?)(\d+)(?:\.(\d*))?$/D', $expanded, $matches) !== 1) {
            throw new InvalidArgumentException('The decimal value is invalid.');
        }

        $negative = $matches[1] === '-';

        if ($negative && ! $allowNegative) {
            throw new InvalidArgumentException('A non-negative decimal value is required.');
        }

        $integer = ltrim($matches[2], '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = rtrim($matches[3] ?? '', '0');

        if (mb_strlen($integer) > 48 || mb_strlen($fraction) > 30) {
            throw new InvalidArgumentException('The decimal value exceeds the engine precision.');
        }

        $canonical = $integer.($fraction === '' ? '' : '.'.$fraction);

        return $negative && $canonical !== '0' ? '-'.$canonical : $canonical;
    }

    private function expandScientificNotation(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^([+-]?)(\d+)(?:\.(\d*))?[eE]([+-]?\d+)$/D', $value, $matches) !== 1) {
            return $value;
        }

        $sign = $matches[1];
        $integer = $matches[2];
        $fraction = $matches[3] ?? '';
        $exponent = (int) $matches[4];
        $digits = $integer.$fraction;
        $point = mb_strlen($integer) + $exponent;

        if ($point <= 0) {
            return $sign.'0.'.str_repeat('0', -$point).$digits;
        }

        if ($point >= mb_strlen($digits)) {
            return $sign.$digits.str_repeat('0', $point - mb_strlen($digits));
        }

        return $sign.mb_substr($digits, 0, $point).'.'.mb_substr($digits, $point);
    }
}
