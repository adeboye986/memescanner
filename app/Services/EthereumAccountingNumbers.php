<?php

namespace App\Services;

use App\Exceptions\EthereumAccountingException;

class EthereumAccountingNumbers
{
    public const MAX = '115792089237316195423570985008687907853269984665640564039457584007913129639935';

    public static function quantity(mixed $hex): string
    {
        if (! is_string($hex) || preg_match('/^0x(?:0|[1-9a-fA-F][0-9a-fA-F]{0,63})$/D', $hex) !== 1) {
            throw new EthereumAccountingException('malformed_quantity', 'discrepancy');
        }

        return self::decode(substr($hex, 2));
    }

    public static function word(mixed $hex): string
    {
        if (! is_string($hex) || preg_match('/^0x[0-9a-fA-F]{64}$/D', $hex) !== 1) {
            throw new EthereumAccountingException('malformed_uint256', 'discrepancy');
        }

        return self::decode(substr($hex, 2));
    }

    private static function decode(string $digits): string
    {
        $value = '0';
        $map = array_combine(str_split('0123456789abcdef'), array_map('strval', range(0, 15)));
        foreach (str_split(strtolower($digits)) as $digit) {
            $value = bcadd(bcmul($value, '16', 0), $map[$digit], 0);
        }

        return $value;
    }

    public static function address(mixed $value): string
    {
        return self::hex($value, 40, 'malformed_address');
    }

    public static function hash(mixed $value): string
    {
        return self::hex($value, 64, 'malformed_hash');
    }

    private static function hex(mixed $value, int $length, string $reason): string
    {
        if (! is_string($value) || preg_match('/^0x[0-9a-fA-F]{'.$length.'}$/D', $value) !== 1) {
            throw new EthereumAccountingException($reason, 'discrepancy');
        }

        return strtolower($value);
    }
}
