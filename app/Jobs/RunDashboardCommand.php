<?php

namespace App\Jobs;

use App\Models\SystemActivity;
use App\Services\DashboardCommandRegistry;
use App\Services\SystemActivityService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

class RunDashboardCommand implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public bool $failOnTimeout = true;

    public function __construct(public int $activityId) {}

    public function handle(
        DashboardCommandRegistry $commands,
        SystemActivityService $activities,
    ): void {
        $activity = SystemActivity::query()->findOrFail($this->activityId);

        if ($activity->status !== 'pending') {
            return;
        }

        $definition = $commands->get($activity->action);
        $output = new BufferedOutput;

        $activities->start($activity);

        try {
            $options = $activity->chain ? ['--chain' => $activity->chain->value] : [];
            if ($activity->user_id && in_array($activity->action, ['token-scan', 'momentum-scan', 'paper-report', 'paper-reconcile'], true)) {
                $options['--user'] = $activity->user_id;
            }
            $exitCode = Artisan::call($definition['command'], $options, $output);
            $activities->finish($activity, $exitCode, $output->fetch());
        } catch (Throwable $exception) {
            $activities->fail($activity, $exception, $output->fetch());

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $activity = SystemActivity::query()->find($this->activityId);

        if (! $activity || $activity->status === 'failed') {
            return;
        }

        $message = $exception?->getMessage() ?? 'The queued command failed unexpectedly.';
        $activity->update([
            'status' => 'failed',
            'finished_at' => now(),
            'duration_seconds' => $activity->started_at
                ? max(0, $activity->started_at->diffInSeconds(now()))
                : 0,
            'exit_code' => 1,
            'error_message' => $message,
        ]);
    }
}
