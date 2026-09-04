<?php

namespace App\Console\Commands;

use App\Services\ApplicationSettingsService;
use App\Services\OperationalHealthService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:health')]
#[Description('Display safe scheduler, queue, tracker, job, and Telegram health')]
class HealthCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(OperationalHealthService $health, ApplicationSettingsService $settings): int
    {
        $status = $health->status();
        $telegram = $settings->get('telegram.enabled') && $settings->getSecret('telegram.bot_token') ? 'CONFIGURED' : 'NOT CONFIGURED';
        $this->table([], [
            ['Scheduler', strtoupper($status['scheduler']['status'])],
            ['Queue', strtoupper($status['queue']['status'])],
            ['Fast Tracker', strtoupper($status['fast_tracker']['status'])],
            ['Failed Jobs', $status['failed_jobs']],
            ['Pending Jobs', $status['pending_jobs']],
            ['Telegram', $telegram],
        ]);

        $unhealthy = collect(['scheduler', 'queue', 'fast_tracker'])
            ->contains(fn (string $component): bool => $status[$component]['status'] !== 'healthy');

        return $unhealthy || $status['failed_jobs'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
