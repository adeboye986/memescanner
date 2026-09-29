<?php

namespace App\Console\Commands;

use App\Services\TradingEngine\TradingEngineProjectionReconciler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('trading-engine:reconcile-projections')]
#[Description('Audit trading engine opportunity projections against the authenticated inbox')]
class ReconcileTradingEngineProjections extends Command
{
    public function handle(TradingEngineProjectionReconciler $reconciler): int
    {
        try {
            $result = $reconciler->reconcile();
        } catch (Throwable) {
            $this->error('The trading engine projection reconciliation could not be completed safely.');

            return self::FAILURE;
        }

        $counts = $result['counts'];
        $checked = $result['checked'];

        $this->info('Trading engine projection reconciliation');
        $this->table(['Persisted state', 'Count'], [
            ['Projected recorded source events', (string) $counts['projected_recorded_events']],
            ['Projected evaluated source events', (string) $counts['projected_evaluated_events']],
            ['Opportunity links', (string) $counts['opportunity_links']],
            ['Evaluation projections', (string) $counts['evaluation_projections']],
        ]);
        $this->table(['Audit coverage', 'Checked'], [
            ['Recorded events', (string) $checked['recorded_events']],
            ['Evaluated events', (string) $checked['evaluated_events']],
            ['Opportunity links', (string) $checked['opportunity_links']],
            ['Evaluation projections', (string) $checked['evaluations']],
        ]);
        $this->line('Integrity issue count: '.$result['issue_count']);

        if ($result['issue_count'] === 0) {
            $this->info('Projection reconciliation completed without integrity issues.');

            return self::SUCCESS;
        }

        $this->error('Projection reconciliation detected integrity issues.');
        $this->table(
            ['Issue code', 'Event ID', 'Projection ID', 'Related event ID'],
            array_map(
                fn (array $issue): array => [
                    $issue['code'],
                    $issue['event_id'] ?? '-',
                    $issue['projection_id'] === null ? '-' : (string) $issue['projection_id'],
                    $issue['related_event_id'] ?? '-',
                ],
                $result['issues'],
            ),
        );

        if ($result['issues_truncated']) {
            $this->warn('Issue output was truncated at the safe reporting limit.');
        }

        return self::FAILURE;
    }
}
