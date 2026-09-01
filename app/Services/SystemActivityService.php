<?php

namespace App\Services;

use App\Models\SystemActivity;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class SystemActivityService
{
    public function __construct(private DashboardCommandRegistry $commands) {}

    public function createManual(string $action): SystemActivity
    {
        return DB::transaction(function () use ($action): SystemActivity {
            $definition = $this->commands->get($action);

            $alreadyRunning = SystemActivity::query()
                ->where('action', $action)
                ->whereIn('status', ['pending', 'running'])
                ->lockForUpdate()
                ->exists();

            if ($alreadyRunning) {
                throw new DomainException("{$definition['label']} is already pending or running.");
            }

            return SystemActivity::query()->create([
                'action' => $action,
                'command' => $definition['command'],
                'label' => $definition['label'],
                'status' => 'pending',
                'triggered_by' => 'manual',
            ]);
        });
    }

    public function createScheduled(string $action): SystemActivity
    {
        $definition = $this->commands->get($action);

        return SystemActivity::query()->create([
            'action' => $action,
            'command' => $definition['command'],
            'label' => $definition['label'],
            'status' => 'pending',
            'triggered_by' => 'scheduler',
        ]);
    }

    public function start(SystemActivity $activity): void
    {
        $activity->update([
            'status' => 'running',
            'started_at' => now(),
            'finished_at' => null,
            'duration_seconds' => null,
            'exit_code' => null,
            'output' => null,
            'error_message' => null,
        ]);
    }

    public function finish(SystemActivity $activity, int $exitCode, string $output): void
    {
        $finishedAt = now();
        $startedAt = $activity->started_at ?? $finishedAt;

        $activity->update([
            'status' => $exitCode === 0 ? 'completed' : 'failed',
            'finished_at' => $finishedAt,
            'duration_seconds' => max(0, $startedAt->diffInSeconds($finishedAt)),
            'exit_code' => $exitCode,
            'output' => $output,
            'error_message' => $exitCode === 0 ? null : "Command exited with code {$exitCode}.",
        ]);
    }

    public function fail(SystemActivity $activity, Throwable $exception, string $output = ''): void
    {
        $finishedAt = now();
        $startedAt = $activity->started_at ?? $finishedAt;

        $activity->update([
            'status' => 'failed',
            'finished_at' => $finishedAt,
            'duration_seconds' => max(0, $startedAt->diffInSeconds($finishedAt)),
            'exit_code' => 1,
            'output' => $output,
            'error_message' => $exception->getMessage(),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestManualData(): ?array
    {
        $activity = SystemActivity::query()
            ->where('triggered_by', 'manual')
            ->latest('id')
            ->first();

        return $activity ? $this->present($activity) : null;
    }

    /** @return list<string> */
    public function runningActions(): array
    {
        return SystemActivity::query()
            ->where('triggered_by', 'manual')
            ->whereIn('status', ['pending', 'running'])
            ->pluck('action')
            ->all();
    }

    /**
     * @return array{status: string, last_tracker_check: ?Carbon, last_momentum_scan: ?Carbon, last_token_scan: ?Carbon}
     */
    public function systemStatus(): array
    {
        $latestByAction = SystemActivity::query()
            ->whereIn('action', ['paper-track', 'momentum-scan', 'token-scan'])
            ->latest('id')
            ->get()
            ->unique('action')
            ->keyBy('action');

        /** @var SystemActivity|null $tracker */
        $tracker = $latestByAction->get('paper-track');
        $trackerTime = $this->activityTime($tracker);

        $trackerStatus = match (true) {
            $tracker === null => 'unknown',
            $tracker->status === 'completed' && $trackerTime?->gte(now()->subMinutes(2)) => 'active',
            default => 'stale',
        };

        return [
            'status' => $trackerStatus,
            'last_tracker_check' => $trackerTime,
            'last_momentum_scan' => $this->activityTime($latestByAction->get('momentum-scan')),
            'last_token_scan' => $this->activityTime($latestByAction->get('token-scan')),
        ];
    }

    /** @return array<string, mixed> */
    public function present(SystemActivity $activity): array
    {
        $output = trim((string) $activity->output);
        $summarySource = $output !== '' ? $output : (string) $activity->error_message;

        return [
            'id' => $activity->id,
            'action' => $activity->action,
            'label' => $activity->label,
            'status' => $activity->status,
            'started_at' => $activity->started_at?->format('H:i:s'),
            'finished_at' => $activity->finished_at?->format('H:i:s'),
            'duration_seconds' => $activity->duration_seconds,
            'exit_code' => $activity->exit_code,
            'summary' => Str::limit(preg_replace('/\s+/', ' ', $summarySource) ?? '', 220),
            'output' => $output !== '' ? $output : $activity->error_message,
        ];
    }

    private function activityTime(?SystemActivity $activity): ?Carbon
    {
        return $activity?->finished_at ?? $activity?->started_at ?? $activity?->created_at;
    }
}
