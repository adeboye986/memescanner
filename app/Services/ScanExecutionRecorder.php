<?php

namespace App\Services;

use App\Chain;
use App\Models\SystemActivity;
use App\Models\TradeOpportunity;
use App\Models\TradingEngineOpportunityExport;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ScanExecutionRecorder
{
    /** @var list<string> */
    private const COUNT_FIELDS = [
        'discovered',
        'examined',
        'qualified',
        'qualified_candidates',
        'opportunities_created',
        'opportunities_existing',
        'downstream_failed',
        'skipped',
        'failed',
        'opportunities_correlated',
        'exports_queued',
        'exports_acknowledged',
    ];

    /** @var list<string> */
    private const EXPORT_STATUSES = [
        TradingEngineOpportunityExport::STATUS_QUEUED,
        TradingEngineOpportunityExport::STATUS_SUBMITTING,
        TradingEngineOpportunityExport::STATUS_RETRYING,
        TradingEngineOpportunityExport::STATUS_ACCEPTANCE_UNKNOWN,
        TradingEngineOpportunityExport::STATUS_ACKNOWLEDGED,
        TradingEngineOpportunityExport::STATUS_SKIPPED,
        TradingEngineOpportunityExport::STATUS_PERMANENTLY_FAILED,
    ];

    public function begin(mixed $activityId, string $action, string $scanner, Chain $chain, ?User $user): ?int
    {
        if ($activityId === null || $activityId === '') {
            return null;
        }

        if ((! is_int($activityId) && ! is_string($activityId))
            || preg_match('/^[1-9][0-9]*$/D', (string) $activityId) !== 1) {
            throw new InvalidArgumentException('The scan activity identifier is invalid.');
        }

        $id = filter_var($activityId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($id === false) {
            throw new InvalidArgumentException('The scan activity identifier is invalid.');
        }

        DB::transaction(function () use ($id, $action, $scanner, $chain, $user): void {
            $activity = SystemActivity::query()->lockForUpdate()->find($id);

            if (! $activity
                || $activity->action !== $action
                || $activity->status !== 'running'
                || $activity->chain !== $chain
                || $activity->user_id !== $user?->getKey()) {
                throw new DomainException('The scan activity does not match the executing command context.');
            }

            $activity->forceFill([
                'result_summary' => [
                    'version' => 1,
                    'kind' => 'scanner_execution',
                    'scanner' => $scanner,
                    'chain' => $chain->value,
                    'outcome' => 'running',
                    'zero_result' => null,
                    'counts' => array_fill_keys(self::COUNT_FIELDS, 0),
                    'provider_failures' => [],
                    'failure_codes' => [],
                    'downstream_failure_codes' => [],
                    'exports' => array_fill_keys(self::EXPORT_STATUSES, 0),
                    'error_code' => null,
                ],
                'last_progress_at' => now(),
            ])->save();
        });

        return (int) $id;
    }

    public function discovered(?int $activityId, int $count): void
    {
        $this->setCount($activityId, 'discovered', max(0, $count));
    }

    public function examined(?int $activityId): void
    {
        $this->increment($activityId, 'examined');
    }

    public function qualifiedCandidate(?int $activityId): void
    {
        $this->mutate($activityId, function (array $summary): array {
            $qualified = ((int) data_get($summary, 'counts.qualified_candidates', 0)) + 1;
            $summary['counts']['qualified_candidates'] = $qualified;
            $summary['counts']['qualified'] = $qualified;

            return $summary;
        });
    }

    public function qualified(?int $activityId): void
    {
        $this->qualifiedCandidate($activityId);
    }

    public function candidateFailed(?int $activityId, string $errorCode): void
    {
        $this->mutate($activityId, function (array $summary) use ($errorCode): array {
            $summary['counts']['failed'] = ((int) data_get($summary, 'counts.failed', 0)) + 1;
            $summary['failure_codes'] = $this->incrementCode((array) ($summary['failure_codes'] ?? []), $errorCode);

            return $summary;
        });
    }

    public function downstreamFailed(?int $activityId, string $errorCode): void
    {
        $this->mutate($activityId, function (array $summary) use ($errorCode): array {
            $summary['counts']['downstream_failed'] = ((int) data_get($summary, 'counts.downstream_failed', 0)) + 1;
            $summary['downstream_failure_codes'] = $this->incrementCode(
                (array) ($summary['downstream_failure_codes'] ?? []),
                $errorCode,
            );

            return $summary;
        });
    }

    public function providerFailed(?int $activityId, string $errorCode): void
    {
        $this->mutate($activityId, function (array $summary) use ($errorCode): array {
            $summary['provider_failures'] = $this->incrementCode((array) ($summary['provider_failures'] ?? []), $errorCode);

            return $summary;
        });
    }

    public function linkOpportunity(?int $activityId, TradeOpportunity $opportunity): void
    {
        if ($activityId === null) {
            return;
        }

        DB::transaction(function () use ($activityId, $opportunity): void {
            $activity = SystemActivity::query()->lockForUpdate()->find($activityId);
            $summary = $activity?->result_summary;

            if (! $activity
                || ! is_array($summary)
                || ! $this->isActiveScanSummary($activity, $summary, $opportunity)
                || ! $this->matchesOpportunity($activity, $opportunity)) {
                throw new DomainException('The opportunity does not match an active scan activity boundary.');
            }

            $changes = $activity->tradeOpportunities()->syncWithoutDetaching([$opportunity->getKey()]);

            if (($changes['attached'] ?? []) !== []) {
                $field = $opportunity->wasRecentlyCreated ? 'opportunities_created' : 'opportunities_existing';
                $summary['counts'][$field] = ((int) data_get($summary, 'counts.'.$field, 0)) + 1;
            }

            $this->refreshDownstream($activity, $summary);
            $activity->forceFill([
                'result_summary' => $summary,
                'last_progress_at' => now(),
            ])->save();
        }, 3);
    }

    public function complete(SystemActivity|int $activity, int $exitCode, ?string $errorCode = null): void
    {
        $activityId = $activity instanceof SystemActivity ? $activity->getKey() : $activity;

        $this->mutate($activityId, function (array $summary, SystemActivity $locked) use ($exitCode, $errorCode): array {
            $this->refreshDownstream($locked, $summary);
            $examined = (int) data_get($summary, 'counts.examined', 0);
            $qualified = (int) data_get($summary, 'counts.qualified_candidates', data_get($summary, 'counts.qualified', 0));
            $summary['counts']['qualified'] = $qualified;
            $failed = (int) data_get($summary, 'counts.failed', 0);
            $summary['counts']['skipped'] = max(0, $examined - $qualified - $failed);
            $providerFailures = array_sum(array_column((array) ($summary['provider_failures'] ?? []), 'count'));
            $downstreamFailures = (int) data_get($summary, 'counts.downstream_failed', 0);

            if ($exitCode !== 0) {
                $summary['outcome'] = 'failed';
                $summary['error_code'] = $this->safeCode($errorCode ?? 'COMMAND_FAILED');
            } else {
                $summary['outcome'] = $providerFailures > 0 || $downstreamFailures > 0 ? 'succeeded_with_warnings' : 'succeeded';
                $summary['error_code'] = null;
            }

            $summary['zero_result'] = $exitCode === 0 && $qualified === 0;

            return $summary;
        });
    }

    public function refreshForOpportunity(int $opportunityId): void
    {
        SystemActivity::query()
            ->whereHas('tradeOpportunities', fn ($query) => $query->whereKey($opportunityId))
            ->pluck('system_activities.id')
            ->each(fn (int $activityId) => $this->mutate(
                $activityId,
                function (array $summary, SystemActivity $activity): array {
                    $this->refreshDownstream($activity, $summary);

                    return $summary;
                },
            ));
    }

    private function setCount(?int $activityId, string $field, int $count): void
    {
        $this->mutate($activityId, function (array $summary) use ($field, $count): array {
            $summary['counts'][$field] = $count;

            return $summary;
        });
    }

    private function increment(?int $activityId, string $field): void
    {
        $this->mutate($activityId, function (array $summary) use ($field): array {
            $summary['counts'][$field] = ((int) data_get($summary, 'counts.'.$field, 0)) + 1;

            return $summary;
        });
    }

    private function mutate(?int $activityId, callable $callback): void
    {
        if ($activityId === null) {
            return;
        }

        DB::transaction(function () use ($activityId, $callback): void {
            $activity = SystemActivity::query()->lockForUpdate()->find($activityId);
            $summary = $activity?->result_summary;

            if (! $activity || ! is_array($summary) || ($summary['version'] ?? null) !== 1
                || ($summary['kind'] ?? null) !== 'scanner_execution') {
                return;
            }

            $activity->forceFill([
                'result_summary' => $callback($summary, $activity),
                'last_progress_at' => now(),
            ])->save();
        });
    }

    /** @param array<string, mixed>|null $summary */
    private function refreshDownstream(SystemActivity $activity, ?array &$summary = null): void
    {
        $summary ??= (array) $activity->result_summary;
        $opportunityIds = $activity->tradeOpportunities()->pluck('trade_opportunities.id');
        $exports = TradingEngineOpportunityExport::query()
            ->whereIn('trade_opportunity_id', $opportunityIds)
            ->get();
        $exportCounts = array_fill_keys(self::EXPORT_STATUSES, 0);

        foreach ($exports as $export) {
            if (array_key_exists($export->status, $exportCounts)) {
                $exportCounts[$export->status]++;
            }
        }

        $summary['counts']['opportunities_correlated'] = $opportunityIds->count();
        $summary['counts']['exports_queued'] = $exports->whereNotNull('queued_at')->count();
        $summary['counts']['exports_acknowledged'] = $exportCounts[TradingEngineOpportunityExport::STATUS_ACKNOWLEDGED];
        $summary['exports'] = $exportCounts;

        $activity->result_summary = $summary;
    }

    /** @param array<string, mixed> $summary */
    private function isActiveScanSummary(
        SystemActivity $activity,
        array $summary,
        TradeOpportunity $opportunity,
    ): bool {
        return $activity->status === 'running'
            && $activity->finished_at === null
            && ($summary['version'] ?? null) === 1
            && ($summary['kind'] ?? null) === 'scanner_execution'
            && ($summary['outcome'] ?? null) === 'running'
            && ($summary['scanner'] ?? null) === $opportunity->scanner
            && ($summary['chain'] ?? null) === $opportunity->chain->value;
    }

    private function matchesOpportunity(SystemActivity $activity, TradeOpportunity $opportunity): bool
    {
        $expectedAction = match ($opportunity->scanner) {
            'new-token' => 'token-scan',
            'momentum' => 'momentum-scan',
            default => null,
        };

        return $expectedAction !== null
            && $activity->action === $expectedAction
            && $activity->chain === $opportunity->chain
            && $activity->user_id === $opportunity->user_id;
    }

    /**
     * @param  list<array{code: string, count: int}>  $codes
     * @return list<array{code: string, count: int}>
     */
    private function incrementCode(array $codes, string $errorCode): array
    {
        $safeCode = $this->safeCode($errorCode);

        foreach ($codes as &$item) {
            if (($item['code'] ?? null) === $safeCode) {
                $item['count'] = ((int) ($item['count'] ?? 0)) + 1;

                return array_values($codes);
            }
        }

        $codes[] = ['code' => $safeCode, 'count' => 1];

        return array_values($codes);
    }

    private function safeCode(string $errorCode): string
    {
        return preg_match('/^[A-Z][A-Z0-9_]{0,127}$/D', $errorCode) === 1
            ? $errorCode
            : 'UNEXPECTED_FAILURE';
    }
}
