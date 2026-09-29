<?php

namespace App\Console\Commands;

use App\Services\TradingEngine\TradingEngineProjectionStatus as ProjectionStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('trading-engine:projection-status {--event= : Inspect one inbox event by event ID}')]
#[Description('Show safe operational status for trading engine opportunity projections')]
class TradingEngineProjectionStatus extends Command
{
    public function handle(ProjectionStatus $status): int
    {
        $eventOption = $this->option('event');

        if ($eventOption !== null) {
            return $this->inspectEvent($status, (string) $eventOption);
        }

        $snapshot = $status->snapshot();
        $configuration = $snapshot['configuration'];
        $counts = $snapshot['inbox']['counts'];
        $recovery = $snapshot['recovery'];

        $this->info('Trading engine projection status');
        $this->table(['Configuration', 'Value'], [
            ['Engine enabled', $this->yesNo($configuration['engine_enabled'])],
            ['Projection enabled', $this->yesNo($configuration['projection_enabled'])],
            ['Automatic recovery enabled', $this->yesNo($configuration['automatic_recovery_enabled'])],
            ['Recovery batch size', (string) $configuration['batch_size']],
            ['Stored stale after seconds', (string) $configuration['stale_after_seconds']],
            ['Dispatch lease seconds', (string) $configuration['lease_seconds']],
        ]);
        $this->table(['Inbox state', 'Count'], [
            ['Total projectable', (string) $snapshot['inbox']['total_projectable']],
            ['Stored', (string) $counts['stored']],
            ['Projected', (string) $counts['projected']],
            ['Retryable', (string) $counts['retryable']],
            ['Deferred', (string) $counts['deferred']],
            ['Failed', (string) $counts['failed']],
            ['Unhandled', (string) $counts['unhandled']],
        ]);
        $this->table(['Recovery state', 'Count'], [
            ['Stale stored eligible', (string) $recovery['stale_stored_eligible']],
            ['Retryable due', (string) $recovery['retryable_due']],
            ['Deferred causally eligible', (string) $recovery['deferred_causally_eligible']],
            ['Active leases', (string) $recovery['active_leases']],
        ]);
        $this->table(['Oldest', 'Event ID', 'Type', 'Status', 'Received at', 'Age seconds'], [
            $this->oldestRow('Outstanding', $snapshot['oldest']['outstanding']),
            $this->oldestRow('Retryable', $snapshot['oldest']['retryable']),
            $this->oldestRow('Deferred', $snapshot['oldest']['deferred']),
            $this->oldestRow('Failed', $snapshot['oldest']['failed']),
        ]);

        return self::SUCCESS;
    }

    private function inspectEvent(ProjectionStatus $status, string $eventId): int
    {
        if (preg_match('/^[A-Za-z0-9]{26}$/D', $eventId) !== 1) {
            $this->error('The projection event ID must be a 26-character identifier.');

            return self::FAILURE;
        }

        $event = $status->inspect($eventId);

        if ($event === null) {
            $this->error('The trading engine projection event was not found.');

            return self::FAILURE;
        }

        $this->info('Trading engine projection event');
        $this->table(['Metadata', 'Value'], [
            ['Event ID', $event['event_id']],
            ['Event type', $event['event_type']],
            ['Aggregate type', $event['aggregate_type']],
            ['Aggregate ID', $event['aggregate_id']],
            ['Aggregate version', (string) $event['aggregate_version']],
            ['Processing status', $event['handling_status']],
            ['Handling attempts', (string) $event['handling_attempts']],
            ['Handling error code', $event['handling_error_code'] ?? '-'],
            ['Next handling at', $event['next_handling_at'] ?? '-'],
            ['Received at', $event['received_at'] ?? '-'],
            ['Created at', $event['created_at'] ?? '-'],
            ['Handled at', $event['handled_at'] ?? '-'],
            ['Correlation ID', $event['correlation_id']],
            ['Causation ID', $event['causation_id']],
            ['Causal dependency satisfied', $this->yesNoNullable($event['causal_dependency_satisfied'])],
            ['Automatic recovery eligible', $this->yesNo($event['automatic_recovery_eligible'])],
            ['Manual retry eligible', $this->yesNo($event['manual_retry_eligible'])],
            ['Active lease', $this->yesNo($event['active_lease'])],
            ['Projection exists', $this->yesNo($event['projection_exists'])],
        ]);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>|null  $oldest
     * @return array<int, string>
     */
    private function oldestRow(string $label, ?array $oldest): array
    {
        if ($oldest === null) {
            return [$label, '-', '-', '-', '-', '-'];
        }

        return [
            $label,
            $oldest['event_id'],
            $oldest['event_type'],
            $oldest['handling_status'],
            $oldest['received_at'],
            (string) $oldest['age_seconds'],
        ];
    }

    private function yesNo(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }

    private function yesNoNullable(?bool $value): string
    {
        return $value === null ? 'not applicable' : $this->yesNo($value);
    }
}
