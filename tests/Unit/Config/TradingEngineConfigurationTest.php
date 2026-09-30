<?php

namespace Tests\Unit\Config;

use Dotenv\Repository\Adapter\ArrayAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Illuminate\Support\Env;
use ReflectionProperty;
use Tests\TestCase;

class TradingEngineConfigurationTest extends TestCase
{
    public function test_integration_defaults_to_disabled_when_environment_overrides_are_absent(): void
    {
        $repositoryProperty = new ReflectionProperty(Env::class, 'repository');
        $originalRepository = $repositoryProperty->getValue();
        $isolatedRepository = RepositoryBuilder::createWithNoAdapters()
            ->addAdapter(ArrayAdapter::class)
            ->make();

        try {
            $repositoryProperty->setValue($isolatedRepository);
            $services = require config_path('services.php');
        } finally {
            $repositoryProperty->setValue($originalRepository);
        }

        $this->assertFalse($services['trading_engine']['enabled']);
        $this->assertFalse($services['trading_engine']['opportunity_export_enabled']);
        $this->assertFalse($services['trading_engine']['opportunity_projection_enabled']);
        $this->assertFalse($services['trading_engine']['evaluation_consumption_enabled']);
        $this->assertFalse($services['trading_engine']['decision_boundary_enabled']);
        $this->assertFalse($services['trading_engine']['paper_decision_integration_enabled']);
        $this->assertFalse($services['trading_engine']['live_decision_integration_enabled']);
        $this->assertFalse($services['trading_engine']['live_preparation_enabled']);
        $this->assertFalse($services['trading_engine']['live_recovery_enabled']);
        $this->assertFalse($services['trading_engine']['solana_live_integration_enabled']);
        $this->assertSame(100, $services['trading_engine']['projection_recovery_batch_size']);
        $this->assertSame(300, $services['trading_engine']['projection_recovery_stale_after_seconds']);
        $this->assertSame(120, $services['trading_engine']['projection_recovery_lease_seconds']);
    }
}
