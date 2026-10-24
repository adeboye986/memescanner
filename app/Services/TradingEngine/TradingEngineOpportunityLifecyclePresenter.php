<?php

namespace App\Services\TradingEngine;

use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Models\TradeOpportunity;
use App\Models\TradingEngineOpportunityEvaluation;
use App\Models\TradingEngineOpportunityExport;
use App\Models\TradingEnginePaperEntryIntent;

class TradingEngineOpportunityLifecyclePresenter
{
    /** @return list<array{label: string, status: string, detail: string, occurred_at: ?string}> */
    public function present(TradeOpportunity $opportunity): array
    {
        $link = $opportunity->engineOpportunityLink;
        $evaluation = $opportunity->engineOpportunityEvaluations
            ->sortByDesc(fn (TradingEngineOpportunityEvaluation $item) => $item->evaluated_at?->getTimestamp() ?? 0)
            ->first();
        $intent = $opportunity->enginePaperEntryIntent;
        $enginePosition = $opportunity->enginePaperPosition;
        $legacyPosition = $opportunity->paperPosition;
        $export = $opportunity->engineOpportunityExport;
        $scanActivity = $opportunity->systemActivities->sortByDesc('id')->first();

        return [
            $this->step(
                'Dashboard scan correlation',
                $scanActivity ? 'complete' : 'unknown',
                $scanActivity
                    ? 'Durably linked to dashboard scan run #'.$scanActivity->getKey().'.'
                    : 'No dashboard scan-run relationship is stored for this historical or non-dashboard opportunity.',
                $scanActivity?->started_at?->toIso8601String() ?? $scanActivity?->created_at?->toIso8601String(),
            ),
            $this->step(
                'Laravel opportunity recorded',
                'complete',
                'The qualified opportunity is durably stored by Laravel.',
                $opportunity->qualified_at?->toIso8601String() ?? $opportunity->created_at?->toIso8601String(),
            ),
            $this->exportQueuedStep($export, $link !== null),
            $this->exportAcknowledgementStep($export, $link !== null, $link?->recorded_at?->toIso8601String()),
            $this->step(
                'Engine evaluation execution',
                $evaluation ? 'complete' : 'not_available',
                $evaluation
                    ? 'A completed evaluation record exists.'
                    : 'Pending and running evaluation states are not exposed by the current durable Laravel projection.',
                $evaluation?->evaluated_at?->toIso8601String(),
            ),
            $this->step(
                'Evaluation result received',
                $evaluation ? 'complete' : 'unknown',
                $evaluation
                    ? 'Outcome: '.str($evaluation->outcome)->replace('_', ' ')->upper().'.'
                    : 'No durable evaluation result is linked to this opportunity.',
                $evaluation?->event_received_at?->toIso8601String(),
            ),
            $this->step(
                'Laravel evaluation projection',
                $evaluation?->projected_at ? 'complete' : ($evaluation ? 'unknown' : 'not_available'),
                $evaluation?->projected_at
                    ? 'The authenticated engine event was projected into Laravel.'
                    : 'No completed Laravel evaluation projection is recorded.',
                $evaluation?->projected_at?->toIso8601String(),
            ),
            $this->paperDecisionStep($opportunity, $evaluation, $intent, $legacyPosition !== null, $enginePosition !== null),
            $this->paperEntryStep($opportunity, $intent, $legacyPosition !== null, $enginePosition !== null),
        ];
    }

    /** @return array{label: string, status: string, detail: string, occurred_at: ?string} */
    private function exportQueuedStep(?TradingEngineOpportunityExport $export, bool $hasAcknowledgementLink): array
    {
        if ($export === null) {
            return $this->step(
                'Engine export queued',
                'not_available',
                $hasAcknowledgementLink
                    ? 'This historical acknowledgement predates durable Laravel export-queue tracking.'
                    : 'No durable export-queue record is linked to this opportunity.',
            );
        }

        if ($export->queued_at === null && $hasAcknowledgementLink) {
            return $this->step(
                'Engine export queued',
                'not_available',
                'Authenticated engine acceptance exists, but this historical export has no durable queue-acceptance timestamp.',
            );
        }

        $status = match ($export->status) {
            TradingEngineOpportunityExport::STATUS_SKIPPED => 'skipped',
            TradingEngineOpportunityExport::STATUS_PERMANENTLY_FAILED => 'failed',
            default => 'complete',
        };

        $detail = match ($export->status) {
            TradingEngineOpportunityExport::STATUS_SKIPPED => 'Export was explicitly skipped by a configured integration boundary'.($this->safeErrorCode($export->last_error_code) ? ': '.$this->safeErrorCode($export->last_error_code).'.' : '.'),
            TradingEngineOpportunityExport::STATUS_PERMANENTLY_FAILED => $export->queued_at === null
                ? 'Queue persistence failed before durable queue acceptance'.($this->safeErrorCode($export->last_error_code) ? ': '.$this->safeErrorCode($export->last_error_code).'.' : '.')
                : 'The queued export reached a permanent failure'.($this->safeErrorCode($export->last_error_code) ? ': '.$this->safeErrorCode($export->last_error_code).'.' : '.'),
            default => 'Laravel durably recorded this opportunity for engine export.',
        };

        return $this->step(
            'Engine export queued',
            $status,
            $detail,
            $export->queued_at?->toIso8601String() ?? $export->created_at?->toIso8601String(),
        );
    }

    /** @return array{label: string, status: string, detail: string, occurred_at: ?string} */
    private function exportAcknowledgementStep(
        ?TradingEngineOpportunityExport $export,
        bool $hasAcknowledgementLink,
        ?string $linkRecordedAt,
    ): array {
        if ($export === null) {
            return $this->step(
                'Engine export acknowledged',
                $hasAcknowledgementLink ? 'complete' : 'unknown',
                $hasAcknowledgementLink
                    ? 'Laravel recorded the engine acknowledgement before durable export-state tracking was added.'
                    : 'No durable engine acknowledgement is linked to this opportunity.',
                $linkRecordedAt,
            );
        }

        if ($hasAcknowledgementLink && $export->status !== TradingEngineOpportunityExport::STATUS_ACKNOWLEDGED) {
            return $this->step(
                'Engine export acknowledged',
                'complete',
                'An authenticated opportunity.recorded event proves engine acceptance and supersedes an earlier delivery outcome.',
                $linkRecordedAt,
            );
        }

        $status = match ($export->status) {
            TradingEngineOpportunityExport::STATUS_ACKNOWLEDGED => 'complete',
            TradingEngineOpportunityExport::STATUS_QUEUED,
            TradingEngineOpportunityExport::STATUS_SUBMITTING,
            TradingEngineOpportunityExport::STATUS_RETRYING => 'pending',
            TradingEngineOpportunityExport::STATUS_ACCEPTANCE_UNKNOWN => 'unknown',
            TradingEngineOpportunityExport::STATUS_SKIPPED => 'skipped',
            TradingEngineOpportunityExport::STATUS_PERMANENTLY_FAILED => 'failed',
            default => 'unknown',
        };

        $detail = match ($export->status) {
            TradingEngineOpportunityExport::STATUS_ACKNOWLEDGED => 'The TypeScript engine acknowledged the immutable opportunity command.',
            TradingEngineOpportunityExport::STATUS_QUEUED => 'The export is queued; no engine acknowledgement has been recorded.',
            TradingEngineOpportunityExport::STATUS_SUBMITTING => 'A Laravel queue worker is submitting the export; no acknowledgement has been recorded.',
            TradingEngineOpportunityExport::STATUS_RETRYING => 'The export is waiting for another bounded queue attempt'.($this->safeErrorCode($export->last_error_code) ? ': '.$this->safeErrorCode($export->last_error_code).'.' : '.'),
            TradingEngineOpportunityExport::STATUS_ACCEPTANCE_UNKNOWN => 'Laravel could not establish whether the engine accepted this immutable command. Missing webhook evidence is not treated as rejection.',
            TradingEngineOpportunityExport::STATUS_SKIPPED => 'The export was skipped; engine evaluation and PAPER entry were not implied.',
            TradingEngineOpportunityExport::STATUS_PERMANENTLY_FAILED => 'The export permanently failed; no engine acknowledgement was recorded.',
            default => 'The durable export record has an unrecognized state; no downstream state has been inferred.',
        };

        return $this->step(
            'Engine export acknowledged',
            $status,
            $detail,
            $export->acknowledged_at?->toIso8601String() ?? $export->last_attempt_at?->toIso8601String(),
        );
    }

    private function safeErrorCode(?string $errorCode): ?string
    {
        return is_string($errorCode) && preg_match('/^[A-Z][A-Z0-9_]{0,127}$/D', $errorCode) === 1
            ? $errorCode
            : null;
    }

    /** @return array{label: string, status: string, detail: string, occurred_at: ?string} */
    private function paperDecisionStep(
        TradeOpportunity $opportunity,
        ?TradingEngineOpportunityEvaluation $evaluation,
        ?TradingEnginePaperEntryIntent $intent,
        bool $hasLegacyPosition,
        bool $hasEnginePosition,
    ): array {
        if ($opportunity->execution_mode !== ExecutionMode::Paper) {
            return $this->step('PAPER decision', 'not_applicable', 'This opportunity was not recorded with PAPER execution selected.');
        }

        if ($opportunity->entry_mode !== EntryMode::Auto) {
            return $this->step(
                'PAPER decision',
                'not_applicable',
                $opportunity->entry_mode === EntryMode::Signal
                    ? 'SIGNAL mode does not automatically create a PAPER entry.'
                    : 'CONFIRM mode requires a user decision; no automatic PAPER entry is inferred.',
            );
        }

        if ($intent || $hasLegacyPosition || $hasEnginePosition) {
            return $this->step(
                'PAPER decision',
                'complete',
                'A durable PAPER entry record proves that the decision path proceeded to entry.',
                $intent?->created_at?->toIso8601String(),
            );
        }

        if ($evaluation && $evaluation->outcome !== 'passed') {
            return $this->step(
                'PAPER decision',
                'unknown',
                'The engine evaluation did not pass, but Laravel does not persist a separate PAPER decision record proving rejection or consumption.',
            );
        }

        return $this->step(
            'PAPER decision',
            'unknown',
            'No durable decision or entry record proves whether Laravel accepted, rejected, or deferred this entry.',
        );
    }

    /** @return array{label: string, status: string, detail: string, occurred_at: ?string} */
    private function paperEntryStep(
        TradeOpportunity $opportunity,
        ?TradingEnginePaperEntryIntent $intent,
        bool $hasLegacyPosition,
        bool $hasEnginePosition,
    ): array {
        if ($opportunity->execution_mode !== ExecutionMode::Paper || $opportunity->entry_mode !== EntryMode::Auto) {
            return $this->step('PAPER entry', 'not_applicable', 'Automatic PAPER entry does not apply to this recorded mode combination.');
        }

        if ($hasEnginePosition) {
            return $this->step('PAPER entry', 'complete', 'An engine-owned PAPER position projection exists.', $intent?->projected_at?->toIso8601String());
        }

        if ($hasLegacyPosition) {
            return $this->step('PAPER entry', 'complete', 'A legacy Laravel PAPER position exists.', $opportunity->executed_at?->toIso8601String());
        }

        if ($intent) {
            return $this->step(
                'PAPER entry',
                in_array($intent->status, ['pending', 'submitted', 'projected', 'failed'], true)
                    ? $intent->status
                    : 'unknown',
                match ($intent->status) {
                    'pending' => 'The engine entry intent is waiting for Laravel queue submission or retry.',
                    'submitted' => 'The engine accepted the entry command; Laravel has not projected a position yet.',
                    'projected' => 'The entry event was projected, but no linked position projection was found.',
                    'failed' => 'The entry intent failed. Provider or credential details are intentionally hidden.',
                    default => 'The entry intent has an unrecognized durable status; no state has been inferred.',
                },
                $intent->projected_at?->toIso8601String() ?? $intent->submitted_at?->toIso8601String() ?? $intent->created_at?->toIso8601String(),
            );
        }

        return $this->step('PAPER entry', 'unknown', 'No durable PAPER entry intent or position is linked to this opportunity.');
    }

    /** @return array{label: string, status: string, detail: string, occurred_at: ?string} */
    private function step(string $label, string $status, string $detail, ?string $occurredAt = null): array
    {
        return [
            'label' => $label,
            'status' => $status,
            'detail' => $detail,
            'occurred_at' => $occurredAt,
        ];
    }
}
