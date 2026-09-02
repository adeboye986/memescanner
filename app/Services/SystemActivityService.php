<?php

namespace App\Services;

use App\Chain;
use App\Models\SystemActivity;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class SystemActivityService
{
    public function __construct(
        private DashboardCommandRegistry $commands,
        private PaperTrackerHealthService $trackerHealth,
    ) {}

    public function createManual(string $action, ?Chain $chain = null): SystemActivity
    {
        return DB::transaction(function () use ($action, $chain): SystemActivity {
            $definition = $this->commands->get($action);

            $alreadyRunning = SystemActivity::query()
                ->where('action', $action)
                ->where('chain', $chain?->value)
                ->whereIn('status', ['pending', 'running'])
                ->lockForUpdate()
                ->exists();

            if ($alreadyRunning) {
                throw new DomainException("{$definition['label']} is already pending or running.");
            }

            return SystemActivity::query()->create([
                'action' => $action,
                'chain' => $chain?->value,
                'command' => $definition['command'],
                'label' => $definition['label'].($chain ? ' — '.$chain->label() : ''),
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
    public function currentManualData(): ?array
    {
        $activity = SystemActivity::query()
            ->where('triggered_by', 'manual')
            ->whereIn('status', ['pending', 'running'])
            ->latest('id')
            ->first();

        return $activity ? $this->present($activity) : null;
    }

    /** @return list<array<string, mixed>> */
    public function recentData(int $limit = 8): array
    {
        return SystemActivity::query()
            ->latest('id')
            ->limit(max(1, min($limit, 10)))
            ->get()
            ->map(fn (SystemActivity $activity): array => $this->present($activity))
            ->all();
    }

    /** @return list<string> */
    public function runningActions(): array
    {
        return SystemActivity::query()
            ->whereIn('status', ['pending', 'running'])
            ->where(function (Builder $query): void {
                $query
                    ->where('triggered_by', 'manual')
                    ->orWhere('action', 'paper-track');
            })
            ->get(['action', 'chain'])
            ->map(fn (SystemActivity $activity): string => $activity->chain
                ? $activity->action.':'.$activity->chain->value
                : $activity->action)
            ->all();
    }

    /** @return array<string, mixed> */
    public function systemStatus(): array
    {
        $latestByAction = SystemActivity::query()
            ->whereIn('action', ['paper-track', 'momentum-scan', 'token-scan'])
            ->latest('id')
            ->get()
            ->unique('action')
            ->keyBy('action');

        $trackerStatus = $this->trackerHealth->status();

        return [
            ...$trackerStatus,
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
            'action_key' => $activity->chain ? $activity->action.':'.$activity->chain->value : $activity->action,
            'chain' => $activity->chain?->value,
            'label' => $activity->label,
            'status' => $activity->status,
            'triggered_by' => $activity->triggered_by,
            'started_at' => $activity->started_at?->format('H:i:s'),
            'started_at_iso' => $activity->started_at?->toIso8601String(),
            'finished_at' => $activity->finished_at?->format('H:i:s'),
            'duration_seconds' => $activity->duration_seconds,
            'running_seconds' => $activity->started_at && ! $activity->finished_at
                ? max(0, $activity->started_at->diffInSeconds(now()))
                : null,
            'exit_code' => $activity->exit_code,
            'relative_time' => $this->activityTime($activity)?->diffForHumans() ?? 'Unknown',
            'summary' => Str::limit(preg_replace('/\s+/', ' ', $summarySource) ?? '', 220),
            'output' => $output !== '' ? $output : $activity->error_message,
        ];
    }

    private function activityTime(?SystemActivity $activity): ?Carbon
    {
        return $activity?->finished_at ?? $activity?->started_at ?? $activity?->created_at;
    }
}
