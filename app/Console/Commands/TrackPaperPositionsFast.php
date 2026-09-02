<?php

namespace App\Console\Commands;

use App\Services\Chains\ChainManager;
use App\Services\DatabaseLockRetryService;
use App\Services\PaperStrategyService;
use App\Services\PaperTrackerHealthService;
use App\Services\PaperWalletService;
use App\Services\TelegramService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('tokens:paper-track:fast
    {--interval= : Target milliseconds between cycle starts}
    {--limit=50 : Maximum funded open positions per cycle}
    {--max-cycles=0 : Stop after this many cycles; zero runs continuously}')]
#[Description('Continuously evaluate funded paper positions at high frequency')]
class TrackPaperPositionsFast extends Command
{
    public function handle(
        TrackPaperPositions $tracker,
        ChainManager $chains,
        TelegramService $telegram,
        PaperWalletService $wallets,
        PaperStrategyService $strategies,
        DatabaseLockRetryService $databaseLocks,
        PaperTrackerHealthService $health,
    ): int {
        $lockSeconds = max(30, (int) config('services.trading.paper_tracker_lock_seconds', 300));
        $cache = Cache::store((string) config('services.trading.paper_tracker_cache_store', 'file'));
        $lock = $cache->lock('paper-tracker.fast.process', $lockSeconds);

        if (! $lock->get()) {
            $this->error('Another fast paper tracker already owns the process lock.');

            return self::FAILURE;
        }

        $targetMilliseconds = max(
            100,
            (int) ($this->option('interval') ?: config('services.trading.paper_tracker_interval_ms', 1000)),
        );
        $rateLimitBackoff = max(
            $targetMilliseconds,
            (int) config('services.trading.paper_tracker_rate_limit_backoff_ms', 5000),
        );
        $maxCycles = max(0, (int) $this->option('max-cycles'));
        $shouldStop = false;
        $cycles = 0;

        if (extension_loaded('pcntl') && defined('SIGTERM') && defined('SIGINT')) {
            $this->trap([SIGTERM, SIGINT], function () use (&$shouldStop): void {
                $shouldStop = true;
                $this->warn('Shutdown requested; stopping after the current cycle.');
            });
        }

        $this->info("Fast paper tracker started with a {$targetMilliseconds}ms target interval.");

        try {
            while (! $shouldStop && ($maxCycles === 0 || $cycles < $maxCycles)) {
                $cycleStarted = hrtime(true);
                $tracker->setOutput($this->getOutput());
                try {
                    $exitCode = $tracker->trackCycle(
                        $chains,
                        $telegram,
                        $wallets,
                        $strategies,
                        $databaseLocks,
                        max(1, min((int) $this->option('limit'), 200)),
                        true,
                    );
                } catch (\Throwable $exception) {
                    if (! $databaseLocks->isLockException($exception)) {
                        throw $exception;
                    }

                    $this->warn('PAPER TRACK DB LOCK: cycle skipped after retries');
                    $exitCode = self::SUCCESS;
                }
                $durationMilliseconds = (hrtime(true) - $cycleStarted) / 1_000_000;
                $metrics = $tracker->cycleMetrics();
                $health->recordCycle($metrics, $durationMilliseconds);
                $cycles++;

                if ($exitCode !== self::SUCCESS) {
                    $this->warn("Tracker cycle {$cycles} exited with code {$exitCode}.");
                }

                if (method_exists($lock, 'refresh') && ! $lock->refresh($lockSeconds)) {
                    $this->error('Fast tracker process lock was lost; shutting down.');

                    return self::FAILURE;
                }

                if ($shouldStop || ($maxCycles > 0 && $cycles >= $maxCycles)) {
                    break;
                }

                $cycleBudget = $metrics['rate_limited'] ? $rateLimitBackoff : $targetMilliseconds;
                $remainingMilliseconds = $cycleBudget - $durationMilliseconds;

                if ($remainingMilliseconds > 0) {
                    usleep((int) round($remainingMilliseconds * 1000));
                }
            }
        } finally {
            $lock->release();
            $this->info("Fast paper tracker stopped after {$cycles} cycle(s).");
        }

        return self::SUCCESS;
    }
}
