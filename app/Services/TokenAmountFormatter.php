<?php

namespace App\Services;

use InvalidArgumentException;

class TokenAmountFormatter
{
    public function format(string $baseUnits, int $decimals): string
    {
        if (preg_match('/^\d+$/', $baseUnits) !== 1) {
            throw new InvalidArgumentException('Token base units must be an unsigned integer string.');
        }

        if ($decimals < 0 || $decimals > 18) {
            throw new InvalidArgumentException('Token decimals are outside the supported range.');
        }

        $baseUnits = ltrim($baseUnits, '0') ?: '0';

        if ($decimals === 0) {
            return $baseUnits;
        }

        $padded = str_pad($baseUnits, $decimals + 1, '0', STR_PAD_LEFT);
        $whole = substr($padded, 0, -$decimals);
        $fraction = rtrim(substr($padded, -$decimals), '0');

        return $fraction === '' ? $whole : $whole.'.'.$fraction;
    }
}
