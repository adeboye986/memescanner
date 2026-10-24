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
            if (in_array($activity->action, ['token-scan', 'momentum-scan'], true)) {
                $options['--activity-id'] = (string) $activity->getKey();
            }
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

        app(SystemActivityService::class)->fail(
            $activity,
            $exception ?? new \RuntimeException('The queued command failed unexpectedly.'),
        );
    }
}
