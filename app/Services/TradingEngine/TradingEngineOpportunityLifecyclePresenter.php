<?php

namespace App\Services\TradingEngine;

use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Models\TradeOpportunity;
use App\Models\TradingEngineOpportunityEvaluation;
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

        return [
            $this->step(
                'Opportunity discovered',
                'complete',
                'The persisted opportunity identifies the '.$opportunity->scanner.' discovery source; a separate discovery timestamp or scan-run correlation is not stored.',
            ),
            $this->step(
                'Laravel opportunity recorded',
                'complete',
                'The qualified opportunity is durably stored by Laravel.',
                $opportunity->qualified_at?->toIso8601String() ?? $opportunity->created_at?->toIso8601String(),
            ),
            $this->step(
                'Engine export queued',
                'not_available',
                $link
                    ? 'An engine acknowledgement exists, but Laravel does not store queue acceptance as a separate stage.'
                    : 'Laravel does not persist a separate per-opportunity queue-acceptance record.',
            ),
            $this->step(
                'Engine export acknowledged',
                $link ? 'complete' : 'unknown',
                $link
                    ? 'Laravel recorded the engine opportunity identifier and acknowledgement event.'
                    : 'No durable engine acknowledgement is linked to this opportunity.',
                $link?->recorded_at?->toIso8601String(),
            ),
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
