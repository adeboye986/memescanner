<?php

namespace Tests\Unit\Services\TradingEngine;

use App\Services\TradingEngine\CanonicalDecimal;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CanonicalDecimalTest extends TestCase
{
    #[DataProvider('canonicalProvider')]
    public function test_normalizes_supported_scalar_values(mixed $input, bool $signed, string $expected): void
    {
        $this->assertSame($expected, (new CanonicalDecimal)->normalize($input, $signed));
    }

    public static function canonicalProvider(): array
    {
        return [
            'zero' => ['0.0000', false, '0'],
            'leading zeros from database' => ['00012.34000', false, '12.34'],
            'positive scientific notation' => ['1.25E+3', false, '1250'],
            'negative scientific notation' => ['-1.25e-3', true, '-0.00125'],
            'negative zero' => ['-0.000', true, '0'],
            'integer' => [12000, false, '12000'],
            'float' => [12.5, false, '12.5'],
        ];
    }

    #[DataProvider('invalidProvider')]
    public function test_rejects_invalid_or_out_of_range_values(mixed $input, bool $signed): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CanonicalDecimal)->normalize($input, $signed);
    }

    public static function invalidProvider(): array
    {
        return [
            'negative unsigned value' => ['-1', false],
            'separator' => ['1,000', false],
            'not a number' => ['NaN', false],
            'infinity' => [INF, false],
            'too much scale' => ['0.'.str_repeat('1', 31), false],
            'too much integer precision' => [str_repeat('1', 49), false],
            'array' => [[], false],
        ];
    }
}
