<?php

namespace App\Console\Commands;

use App\Services\OperationalHealthService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Throwable;

#[Signature('app:queue-drain {--max-time= : Maximum worker lifetime in seconds} {--memory= : Memory limit in megabytes}')]
#[Description('Drain queued work in a bounded, cPanel cron-safe process')]
class QueueDrainCommand extends Command
{
    public function handle(OperationalHealthService $health): int
    {
        $maxTime = max(5, (int) ($this->option('max-time') ?: config('services.operations.queue_max_time', 50)));
        $jobTimeout = max(30, (int) config('services.operations.queue_job_timeout', 600));
        $retryAfter = max($jobTimeout, (int) config('queue.connections.database.retry_after', 660));
        $lock = Cache::store((string) config('services.operations.cache_store', 'file'))->lock('operations.queue-drain', $retryAfter + 60);
        if (! $lock->get()) {
            $this->warn('Another queue drain is already running.');

            return self::SUCCESS;
        }

        $processed = 0;
        Queue::after(function (JobProcessed $event) use (&$processed, $health): void {
            $processed++;
            $health->recordQueueJobProcessed();
        });

        try {
            $health->recordQueueRun('running');
            $exitCode = Artisan::call('queue:work', [
                // '--stop-when-empty' => true,
                '--queue' => 'telegram,default',
                '--max-time' => $maxTime,
                '--memory' => max(32, (int) ($this->option('memory') ?: config('services.operations.queue_memory', 128))),
                '--timeout' => $jobTimeout,
                '--sleep' => 1,
                '--tries' => 3,
            ], $this->getOutput());
            $health->recordQueueRun($exitCode === 0 ? 'completed' : 'failed', $processed);

            return $exitCode;
        } catch (Throwable $exception) {
            $health->recordQueueRun('failed', $processed);

            throw $exception;
        } finally {
            $lock->release();
        }
    }
}
