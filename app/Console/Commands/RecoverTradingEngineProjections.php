<?php

namespace App\Console\Commands;

use App\Services\TradingEngine\TradingEngineProjectionRecovery;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('trading-engine:recover-projections')]
#[Description('Dispatch a bounded batch of due trading engine opportunity projections')]
class RecoverTradingEngineProjections extends Command
{
    public function handle(TradingEngineProjectionRecovery $recovery): int
    {
        $result = $recovery->recover();

        if (! $result['enabled']) {
            $this->info('Trading engine opportunity projection recovery is disabled.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Projection recovery selected %d event(s), dispatched %d, failed %d.',
            $result['selected'],
            $result['dispatched'],
            $result['failed'],
        ));

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
