<?php

namespace Tests\Unit;

use App\Services\TokenAmountFormatter;
use Tests\TestCase;

class TokenAmountFormatterTest extends TestCase
{
    public function test_formats_spl_base_units_without_floating_point(): void
    {
        $formatter = app(TokenAmountFormatter::class);

        $this->assertSame('0.104444', $formatter->format('104444', 6));
        $this->assertSame('0.1034', $formatter->format('103400', 6));
        $this->assertSame('0.000044', $formatter->format('44', 6));
        $this->assertSame('0.000001', $formatter->format('1', 6));
    }

    public function test_preserves_very_large_integer_precision(): void
    {
        $formatted = app(TokenAmountFormatter::class)->format('123456789012345678901234567890', 6);

        $this->assertSame('123456789012345678901234.56789', $formatted);
    }
}
