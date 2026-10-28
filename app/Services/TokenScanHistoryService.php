<?php

namespace App\Services;

use App\Models\SystemActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class TokenScanHistoryService
{
    public function __construct(private SystemActivityService $activities) {}

    /**
     * @param  array{status: string, scanner: string, network: string}  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(array $filters, int $page, User $user, int $perPage = 10): LengthAwarePaginator
    {
        $query = $this->queryForUser($user);

        if ($filters['scanner'] !== 'all') {
            $query->where('action', $filters['scanner'] === 'new-token' ? 'token-scan' : 'momentum-scan');
        }

        if ($filters['network'] !== 'all') {
            $query->where('chain', $filters['network']);
        }

        $this->applyStatusFilter($query, $filters['status']);

        return $query
            ->latest('id')
            ->paginate(max(1, min($perPage, 50)), ['*'], 'page', $page)
            ->withQueryString()
            ->through(fn (SystemActivity $activity): array => $this->present($activity));
    }

    public function latest(User $user): ?SystemActivity
    {
        return $this->queryForUser($user)->latest('id')->first();
    }

    public function findForUser(int $activityId, User $user): SystemActivity
    {
        return $this->queryForUser($user)->findOrFail($activityId);
    }

    /** @return array<string, mixed> */
    public function present(SystemActivity $activity): array
    {
        $activity->loadMissing(['user:id,name', 'tradingEngineScanRequest']);
        $presented = $this->activities->present($activity);
        $summary = $presented['result_summary'];
        $reportedCount = fn (string $field): ?int => is_array($summary)
            && data_get($summary, 'availability.counts.'.$field, false)
                ? (int) data_get($summary, 'counts.'.$field)
                : null;
        $opportunitiesCreated = $reportedCount('opportunities_created');
        $opportunitiesExisting = $reportedCount('opportunities_existing');
        $providerWarningsReported = is_array($summary)
            && data_get($summary, 'availability.provider_failures', false);
        $providerWarnings = $providerWarningsReported ? $summary['provider_failures'] : [];
        $status = $this->status($presented);
        $engineRequest = $activity->tradingEngineScanRequest;

        return [
            ...$presented,
            'status_key' => $status['key'],
            'status_label' => $status['label'],
            'scanner_label' => match ($summary['scanner'] ?? null) {
                'new-token' => 'New Token Scanner',
                'momentum' => 'Momentum Scanner',
                default => $activity->action === 'momentum-scan' ? 'Momentum Scanner' : 'New Token Scanner',
            },
            'network_label' => match ($summary['chain'] ?? $activity->chain?->value) {
                'solana' => 'Solana',
                'ethereum' => 'Ethereum',
                default => 'Network unavailable',
            },
            'submitted_at_display' => $activity->created_at?->format('M j, Y H:i') ?? 'Unavailable',
            'submitted_at_iso' => $activity->created_at?->toIso8601String(),
            'completed_at_display' => $activity->finished_at?->format('M j, Y H:i'),
            'completed_at_iso' => $activity->finished_at?->toIso8601String(),
            'counts' => [
                'discovered' => $reportedCount('discovered'),
                'examined' => $reportedCount('examined'),
                'qualified' => $reportedCount('qualified'),
                'failed' => $reportedCount('failed'),
                'skipped' => $reportedCount('skipped'),
                'opportunities_created' => $opportunitiesCreated,
                'opportunities_existing' => $opportunitiesExisting,
                'opportunities' => $opportunitiesCreated !== null && $opportunitiesExisting !== null
                    ? $opportunitiesCreated + $opportunitiesExisting
                    : $opportunitiesCreated,
            ],
            'provider_warnings' => collect($providerWarnings)->map(fn (array $warning): array => [
                ...$warning,
                'explanation' => $this->providerWarningExplanation($warning['code']),
            ])->all(),
            'provider_warning_count' => $providerWarningsReported
                ? collect($providerWarnings)->sum('count')
                : null,
            'source_owner' => $engineRequest === null ? 'Laravel scanner' : 'TypeScript engine',
            'source_owner_detail' => $engineRequest === null
                ? 'Historical Laravel-owned execution'
                : 'Engine-owned execution projected into Laravel',
            'engine_scan_execution_id' => $engineRequest?->engine_scan_execution_id,
            'engine_delivery_status' => $engineRequest?->status,
        ];
    }

    public function providerWarningExplanation(string $code): string
    {
        return match ($code) {
            'PROVIDER_RATE_LIMITED' => 'A market-data provider rate-limited one or more requests. Candidate rows identify which evaluations were affected when diagnostics are available.',
            'DISCOVERY_PROVIDER_UNAVAILABLE' => 'The discovery provider was unavailable for part of this scan.',
            'MARKET_PROVIDER_UNAVAILABLE' => 'Market data was unavailable for part of this scan.',
            'SECURITY_PROVIDER_UNAVAILABLE' => 'Security data was unavailable for part of this scan.',
            'HOLDER_PROVIDER_UNAVAILABLE' => 'Holder analysis was unavailable for part of this scan.',
            default => 'The scan recorded a bounded provider warning. The technical code is retained for support.',
        };
    }

    private function queryForUser(User $user): Builder
    {
        return SystemActivity::query()
            ->with(['user:id,name', 'tradingEngineScanRequest'])
            ->whereIn('action', ['token-scan', 'momentum-scan'])
            ->where(function (Builder $query) use ($user): void {
                $query->where('user_id', $user->getKey());
                if ($user->is_admin) {
                    $query->orWhereNull('user_id');
                }
            });
    }

    private function applyStatusFilter(Builder $query, string $status): void
    {
        match ($status) {
            'pending', 'running', 'failed' => $query->where('status', $status),
            'completed_with_warnings' => $query
                ->where('status', 'completed')
                ->where('result_summary->outcome', 'succeeded_with_warnings'),
            'completed' => $query
                ->where('status', 'completed')
                ->where(function (Builder $query): void {
                    $query
                        ->whereNull('result_summary->outcome')
                        ->orWhere('result_summary->outcome', '!=', 'succeeded_with_warnings');
                }),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $activity
     * @return array{key: string, label: string}
     */
    private function status(array $activity): array
    {
        $outcome = data_get($activity, 'result_summary.outcome');
        $key = match (true) {
            $activity['status'] === 'pending' => 'pending',
            $activity['status'] === 'running' => 'running',
            $activity['status'] === 'failed' || $outcome === 'failed' => 'failed',
            $outcome === 'succeeded_with_warnings' => 'completed_with_warnings',
            $activity['status'] === 'completed' || $outcome === 'succeeded' => 'completed',
            default => 'unavailable',
        };

        return [
            'key' => $key,
            'label' => match ($key) {
                'pending' => 'Pending',
                'running' => 'Running',
                'completed' => 'Completed',
                'completed_with_warnings' => 'Completed with warnings',
                'failed' => 'Failed',
                default => 'Status unavailable',
            },
        ];
    }
}
