<?php

namespace App\Console\Commands;

use App\Services\TradingEngine\TradingEngineLiveReadiness;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('trading-engine:verify-live-readiness
    {--chain=all : Verify ethereum, solana, or all LIVE readiness}')]
#[Description('Perform a read-only operational readiness check for Trading Engine LIVE capabilities.')]
class VerifyTradingEngineLiveReadiness extends Command
{
    public function handle(TradingEngineLiveReadiness $readiness): int
    {
        $chain = strtolower(trim((string) $this->option('chain')));

        if (! in_array($chain, ['all', 'ethereum', 'solana'], true)) {
            $this->error('The --chain option must be ethereum, solana, or all.');

            return self::INVALID;
        }

        $result = $readiness->inspect($chain);
        $this->info('Trading Engine LIVE Readiness');
        $this->line('Overall: '.$result['overall']);

        foreach (collect($result['checks'])->groupBy('group') as $group => $checks) {
            $this->newLine();
            $this->line((string) $group);

            foreach ($checks as $check) {
                $status = match ($check['status']) {
                    TradingEngineLiveReadiness::STATUS_PASS => 'PASS',
                    TradingEngineLiveReadiness::STATUS_WARNING => 'WARN',
                    default => 'BLOCK',
                };
                $this->line("[{$status}] {$check['name']}: {$check['message']}");
            }
        }

        return $result['ready'] ? self::SUCCESS : self::FAILURE;
    }
}
