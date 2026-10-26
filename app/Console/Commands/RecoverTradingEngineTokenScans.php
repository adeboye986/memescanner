<?php

namespace App\Console\Commands;

use App\Services\TradingEngine\TradingEngineScanDeliveryTracker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('trading-engine:recover-token-scans {--limit=100 : Maximum stale scan requests to inspect}')]
#[Description('Recover a bounded batch of stale durable TypeScript scan deliveries')]
class RecoverTradingEngineTokenScans extends Command
{
    public function handle(TradingEngineScanDeliveryTracker $deliveries): int
    {
        if (config('services.trading_engine.enabled', false) !== true
            || config('services.trading_engine.scanner_integration_enabled', false) !== true) {
            $this->info('Trading engine token-scan delivery recovery is disabled.');

            return self::SUCCESS;
        }

        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 500],
        ]);

        if (! is_int($limit)) {
            $this->error('The recovery limit must be an integer between 1 and 500.');

            return self::INVALID;
        }

        $this->info(sprintf('Recovered %d token-scan delivery job(s).', $deliveries->recover($limit)));

        return self::SUCCESS;
    }
}
