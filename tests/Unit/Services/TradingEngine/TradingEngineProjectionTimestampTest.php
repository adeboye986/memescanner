<?php

namespace Tests\Unit\Services\TradingEngine;

use App\Services\TradingEngine\TradingEngineProjectionTimestamp;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class TradingEngineProjectionTimestampTest extends TestCase
{
    public function test_fractional_and_whole_second_timestamps_represent_the_same_stored_instant(): void
    {
        $timestamps = new TradingEngineProjectionTimestamp;

        $equivalent = $timestamps->representsSameStoredInstant(
            new DateTimeImmutable('2026-10-02T18:05:14.755Z'),
            new DateTimeImmutable('2026-10-02T19:05:14+01:00'),
        );

        $this->assertTrue($equivalent);
    }

    public function test_different_whole_second_timestamps_do_not_represent_the_same_stored_instant(): void
    {
        $timestamps = new TradingEngineProjectionTimestamp;

        $equivalent = $timestamps->representsSameStoredInstant(
            new DateTimeImmutable('2026-10-02T18:05:14.999Z'),
            new DateTimeImmutable('2026-10-02T18:05:15Z'),
        );

        $this->assertFalse($equivalent);
    }
}
