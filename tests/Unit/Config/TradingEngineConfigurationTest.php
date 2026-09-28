<?php

namespace Tests\Unit\Config;

use Illuminate\Support\Env;
use Tests\TestCase;

class TradingEngineConfigurationTest extends TestCase
{
    public function test_integration_is_disabled_by_the_actual_configuration_when_environment_override_is_absent(): void
    {
        $this->assertNull(Env::get('TRADING_ENGINE_ENABLED'));
        $this->assertFalse(config('services.trading_engine.enabled'));
    }
}
