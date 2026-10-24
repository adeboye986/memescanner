<?php

namespace App\Services;

use App\Chain;
use App\Models\SystemActivity;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class SystemActivityService
{
    public function __construct(
        private DashboardCommandRegistry $commands,
        private PaperTrackerHealthService $trackerHealth,
        private ScanExecutionRecorder $scanExecutions,
    ) {}

    public function createManual(string $action, ?Chain $chain = null, ?User $user = null): SystemActivity
    {
        return DB::transaction(function () use ($action, $chain, $user): SystemActivity {
            $definition = $this->commands->get($action);

            $userScoped = in_array($action, ['token-scan', 'momentum-scan', 'paper-report', 'paper-reconcile'], true);
            $alreadyRunning = SystemActivity::query()
                ->where('action', $action)
                ->where('chain', $chain?->value)
                ->when($userScoped, fn ($query) => $query->where('user_id', $user?->id))
                ->whereIn('status', ['pending', 'running'])
                ->lockForUpdate()
                ->exists();

            if ($alreadyRunning) {
                throw new DomainException("{$definition['label']} is already pending or running.");
            }

            return SystemActivity::query()->create([
                'action' => $action,
                'user_id' => $user?->id,
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
            'result_summary' => null,
            'last_progress_at' => null,
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
        $this->scanExecutions->complete(
            $activity->getKey(),
            $exitCode,
            $exitCode === 0 ? null : 'COMMAND_EXIT_NONZERO',
        );
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
        $this->scanExecutions->complete($activity->getKey(), 1, 'COMMAND_EXCEPTION');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function currentManualData(?User $user = null): ?array
    {
        $activity = SystemActivity::query()
            ->with('user:id,name')
            ->when($user, fn ($query) => $query->where(function ($query) use ($user): void {
                $query->where('user_id', $user->id);
                if ($user->is_admin) {
                    $query->orWhereNull('user_id');
                }
            }))
            ->where('triggered_by', 'manual')
            ->whereIn('status', ['pending', 'running'])
            ->latest('id')
            ->first();

        return $activity ? $this->present($activity) : null;
    }

    /** @return list<array<string, mixed>> */
    public function activeData(?User $user = null): array
    {
        return SystemActivity::query()
            ->with('user:id,name')
            ->when($user, fn ($query) => $query->where(function ($query) use ($user): void {
                $query->where('user_id', $user->id);
                if ($user->is_admin) {
                    $query->orWhereNull('user_id');
                }
            }))
            ->whereIn('status', ['pending', 'running'])
            ->oldest('id')
            ->get()
            ->map(fn (SystemActivity $activity): array => $this->present($activity))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    public function recentData(int $limit = 8, ?User $user = null): array
    {
        return SystemActivity::query()
            ->with('user:id,name')
            ->when($user, fn ($query) => $query->where(function ($query) use ($user): void {
                $query->where('user_id', $user->id);
                if ($user->is_admin) {
                    $query->orWhereNull('user_id');
                }
            }))
            ->latest('id')
            ->limit(max(1, min($limit, 10)))
            ->get()
            ->map(fn (SystemActivity $activity): array => $this->present($activity))
            ->all();
    }

    /** @return list<string> */
    public function runningActions(?User $user = null): array
    {
        return SystemActivity::query()
            ->when($user, fn ($query) => $query->where(function ($query) use ($user): void {
                $query->where('user_id', $user->id);
                if ($user->is_admin) {
                    $query->orWhereNull('user_id');
                }
            }))
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
        return [
            'id' => $activity->id,
            'action' => $activity->action,
            'action_key' => $activity->chain ? $activity->action.':'.$activity->chain->value : $activity->action,
            'chain' => $activity->chain?->value,
            'label' => $activity->label,
            'status' => $activity->status,
            'triggered_by' => $activity->triggered_by,
            'initiated_by' => $activity->user?->name ?? ($activity->triggered_by === 'scheduler' ? 'Laravel scheduler' : 'System'),
            'started_at' => $activity->started_at?->format('H:i:s'),
            'started_at_iso' => $activity->started_at?->toIso8601String(),
            'finished_at' => $activity->finished_at?->format('H:i:s'),
            'duration_seconds' => $activity->duration_seconds,
            'running_seconds' => $activity->started_at && ! $activity->finished_at
                ? max(0, $activity->started_at->diffInSeconds(now()))
                : null,
            'exit_code' => $activity->exit_code,
            'relative_time' => $this->activityTime($activity)?->diffForHumans() ?? 'Unknown',
            'last_progress_at' => $activity->last_progress_at?->toIso8601String(),
            'result_summary' => $this->safeResultSummary($activity),
            'summary' => $this->safeSummary($activity),
        ];
    }

    private function safeSummary(SystemActivity $activity): string
    {
        $isScanner = in_array($activity->action, ['token-scan', 'momentum-scan'], true);
        $result = $this->safeResultSummary($activity);

        if ($isScanner && $result !== null && in_array($activity->status, ['completed', 'failed'], true)) {
            $counts = $result['counts'];
            $errorCode = $result['error_code'];

            if ($activity->status === 'failed') {
                return 'The Laravel scanner command failed with safe code '.($errorCode ?? 'COMMAND_FAILED').'. Internal diagnostics were retained for operators.';
            }

            if ($result['zero_result'] === true) {
                return sprintf(
                    'The Laravel scanner completed successfully with zero qualified opportunities after examining %d of %d discovered candidates.',
                    $counts['examined'],
                    $counts['discovered'],
                );
            }

            return sprintf(
                'The Laravel scanner completed: %d discovered, %d examined, %d qualified, %d skipped, %d failed; %d engine exports acknowledged.',
                $counts['discovered'],
                $counts['examined'],
                $counts['qualified'],
                $counts['skipped'],
                $counts['failed'],
                $counts['exports_acknowledged'],
            );
        }

        return match ($activity->status) {
            'pending' => 'Accepted by Laravel and waiting for the Laravel queue worker.',
            'running' => $isScanner
                ? 'The Laravel scanner command is running. Downstream engine processing is tracked separately.'
                : 'The Laravel command is running.',
            'completed' => $isScanner
                ? 'The Laravel scanner command completed successfully. No structured per-run evidence is available for this historical scan.'
                : 'The Laravel command completed successfully.',
            'failed' => $isScanner
                ? 'The Laravel scanner command failed. Internal diagnostics were retained for operators.'
                : 'The Laravel command failed. Internal diagnostics were retained for operators.',
            default => 'The command state is not recognized; no result has been inferred.',
        };
    }

    /** @return array<string, mixed>|null */
    private function safeResultSummary(SystemActivity $activity): ?array
    {
        $summary = $activity->result_summary;

        if (! is_array($summary)
            || ($summary['version'] ?? null) !== 1
            || ($summary['kind'] ?? null) !== 'scanner_execution') {
            return null;
        }

        $counts = [];
        foreach (['discovered', 'examined', 'qualified', 'skipped', 'failed', 'opportunities_correlated', 'exports_queued', 'exports_acknowledged'] as $field) {
            $counts[$field] = max(0, (int) data_get($summary, 'counts.'.$field, 0));
        }

        $exports = [];
        foreach (['queued', 'submitting', 'retrying', 'acknowledged', 'skipped', 'permanently_failed'] as $status) {
            $exports[$status] = max(0, (int) data_get($summary, 'exports.'.$status, 0));
        }

        $safeCodes = fn (mixed $items): array => collect(is_array($items) ? $items : [])
            ->filter(fn (mixed $item): bool => is_array($item)
                && is_string($item['code'] ?? null)
                && preg_match('/^[A-Z][A-Z0-9_]{0,127}$/D', $item['code']) === 1)
            ->map(fn (array $item): array => [
                'code' => $item['code'],
                'count' => max(1, (int) ($item['count'] ?? 1)),
            ])
            ->values()
            ->all();

        $errorCode = $summary['error_code'] ?? null;

        return [
            'version' => 1,
            'kind' => 'scanner_execution',
            'scanner' => in_array($summary['scanner'] ?? null, ['new-token', 'momentum'], true)
                ? $summary['scanner']
                : 'unknown',
            'chain' => in_array($summary['chain'] ?? null, ['solana', 'ethereum'], true)
                ? $summary['chain']
                : 'unknown',
            'outcome' => in_array($summary['outcome'] ?? null, ['running', 'succeeded', 'succeeded_with_warnings', 'failed'], true)
                ? $summary['outcome']
                : 'unknown',
            'zero_result' => is_bool($summary['zero_result'] ?? null) ? $summary['zero_result'] : null,
            'counts' => $counts,
            'provider_failures' => $safeCodes($summary['provider_failures'] ?? []),
            'failure_codes' => $safeCodes($summary['failure_codes'] ?? []),
            'exports' => $exports,
            'error_code' => is_string($errorCode) && preg_match('/^[A-Z][A-Z0-9_]{0,127}$/D', $errorCode) === 1
                ? $errorCode
                : null,
        ];
    }

    private function activityTime(?SystemActivity $activity): ?Carbon
    {
        return $activity?->finished_at ?? $activity?->started_at ?? $activity?->created_at;
    }
}
