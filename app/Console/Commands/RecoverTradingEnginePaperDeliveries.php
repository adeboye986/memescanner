<?php

namespace App\Console\Commands;

use App\Services\TradingEngine\TradingEnginePaperDeliveryTracker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('trading-engine:recover-paper-deliveries {--limit=100 : Maximum records of each command type to inspect}')]
#[Description('Recover a bounded batch of stale durable PAPER command deliveries')]
class RecoverTradingEnginePaperDeliveries extends Command
{
    public function handle(TradingEnginePaperDeliveryTracker $deliveries): int
    {
        if (config('services.trading_engine.enabled', false) !== true) {
            $this->info('Trading engine PAPER delivery recovery is disabled.');

            return self::SUCCESS;
        }

        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 500],
        ]);

        if (! is_int($limit)) {
            $this->error('The recovery limit must be an integer between 1 and 500.');

            return self::INVALID;
        }

        $entries = config('services.trading_engine.paper_entry_integration_enabled', false) === true
            ? $deliveries->recoverEntries($limit)
            : 0;
        $observations = config('services.trading_engine.paper_financial_lifecycle_enabled', false) === true
            ? $deliveries->recoverObservations($limit)
            : 0;

        $this->info(sprintf(
            'Recovered %d PAPER entry delivery job(s) and %d financial observation delivery job(s).',
            $entries,
            $observations,
        ));

        return self::SUCCESS;
    }
}
