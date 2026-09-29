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
    }
}
