<?php

namespace Tests\Unit\Services\TradingEngine;

use App\Services\TradingEngine\TradingEngineCanonicalJson;
use PHPUnit\Framework\TestCase;

class TradingEngineCanonicalJsonTest extends TestCase
{
    public function test_associative_key_order_does_not_affect_equality(): void
    {
        $canonicalJson = new TradingEngineCanonicalJson;

        $this->assertTrue($canonicalJson->equals(
            ['paper_position_id' => '95', 'sequence' => 1],
            ['sequence' => 1, 'paper_position_id' => '95'],
        ));
    }

    public function test_recursive_associative_key_order_does_not_affect_equality(): void
    {
        $canonicalJson = new TradingEngineCanonicalJson;

        $this->assertTrue($canonicalJson->equals(
            [
                'source' => ['paper_position_id' => '95', 'sequence' => 1],
                'market' => ['market_cap_usd' => '8500', 'provider' => 'dexscreener'],
            ],
            [
                'market' => ['provider' => 'dexscreener', 'market_cap_usd' => '8500'],
                'source' => ['sequence' => 1, 'paper_position_id' => '95'],
            ],
        ));
    }

    public function test_list_order_affects_equality(): void
    {
        $canonicalJson = new TradingEngineCanonicalJson;

        $this->assertFalse($canonicalJson->equals(
            ['PEAK_UPDATED', 'PROTECTION_LEVEL_1_ARMED'],
            ['PROTECTION_LEVEL_1_ARMED', 'PEAK_UPDATED'],
        ));
    }

    public function test_changed_scalar_value_affects_equality(): void
    {
        $canonicalJson = new TradingEngineCanonicalJson;

        $this->assertFalse($canonicalJson->equals(
            ['paper_position_id' => '95'],
            ['paper_position_id' => '96'],
        ));
    }

    public function test_changed_scalar_type_affects_equality(): void
    {
        $canonicalJson = new TradingEngineCanonicalJson;

        $this->assertFalse($canonicalJson->equals(
            ['paper_position_id' => '95', 'authoritative' => true],
            ['paper_position_id' => 95, 'authoritative' => 1],
        ));
    }
}
