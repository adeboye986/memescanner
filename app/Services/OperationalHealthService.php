<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OperationalHealthService
{
    private const SCHEDULER_KEY = 'operations.scheduler.last_run';

    private const QUEUE_KEY = 'operations.queue.health';

    public function recordSchedulerRun(): void
    {
        $this->cache()->forever(self::SCHEDULER_KEY, now()->toIso8601String());
    }

    public function recordQueueRun(string $state, ?int $processed = null): void
    {
        $current = $this->cache()->get(self::QUEUE_KEY, []);
        $data = is_array($current) ? $current : [];
        $data['last_run_at'] = now()->toIso8601String();
        $data['state'] = $state;
        if ($processed !== null) {
            $data['processed_jobs'] = $processed;
        }
        $this->cache()->forever(self::QUEUE_KEY, $data);
    }

    public function recordQueueJobProcessed(): void
    {
        $current = $this->cache()->get(self::QUEUE_KEY, []);
        $data = is_array($current) ? $current : [];
        $data['last_job_processed_at'] = now()->toIso8601String();
        $this->cache()->forever(self::QUEUE_KEY, $data);
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $schedulerAt = $this->date($this->cache()->get(self::SCHEDULER_KEY));
        $queue = $this->cache()->get(self::QUEUE_KEY, []);
        $queue = is_array($queue) ? $queue : [];
        $queueAt = $this->date($queue['last_run_at'] ?? null);
        $tracker = app(PaperTrackerHealthService::class)->status();
        $trackerAt = $tracker['last_tracker_check'];

        return [
            'scheduler' => $this->component($schedulerAt, (int) config('services.operations.scheduler_stale_seconds', 150)),
            'queue' => [...$this->component($queueAt, (int) config('services.operations.queue_stale_seconds', 750)), 'last_job_processed_at' => $this->date($queue['last_job_processed_at'] ?? null), 'processed_jobs' => $queue['processed_jobs'] ?? null],
            'fast_tracker' => $this->component($trackerAt, (int) config('services.operations.fast_tracker_stale_seconds', 75)),
            'pending_jobs' => $this->tableCount('jobs'),
            'failed_jobs' => $this->tableCount('failed_jobs'),
        ];
    }

    /** @return array{status: string, last_run_at: ?Carbon} */
    private function component(?Carbon $lastRun, int $staleSeconds): array
    {
        return ['status' => $lastRun === null ? 'never_run' : ($lastRun->gte(now()->subSeconds(max(1, $staleSeconds))) ? 'healthy' : 'stale'), 'last_run_at' => $lastRun];
    }

    private function tableCount(string $table): int
    {
        return Schema::hasTable($table) ? DB::table($table)->count() : 0;
    }

    private function date(mixed $value): ?Carbon
    {
        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }

    private function cache(): Repository
    {
        return Cache::store((string) config('services.operations.cache_store', 'file'));
    }
}
