<?php

namespace Tests\Unit\Services\TradingEngine;

use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Models\TradeOpportunity;
use App\Models\TradingEngineOpportunityEvaluation;
use App\Models\TradingEngineOpportunityLink;
use App\Models\TradingEnginePaperEntryIntent;
use App\Services\TradingEngine\TradingEngineOpportunityLifecyclePresenter;
use Illuminate\Support\Collection;
use Tests\TestCase;

class TradingEngineOpportunityLifecyclePresenterTest extends TestCase
{
    public function test_it_does_not_invent_unpersisted_queue_evaluation_or_entry_states(): void
    {
        $steps = $this->keyedSteps($this->opportunity());
        $this->assertSame('unknown', $steps['Dashboard scan correlation']['status']);
        $this->assertNull($steps['Dashboard scan correlation']['occurred_at']);

        $this->assertSame('not_available', $steps['Engine export queued']['status']);
        $this->assertSame('unknown', $steps['Engine export acknowledged']['status']);
        $this->assertSame('not_available', $steps['Engine evaluation execution']['status']);
        $this->assertSame('unknown', $steps['Evaluation result received']['status']);
        $this->assertSame('unknown', $steps['PAPER decision']['status']);
        $this->assertSame('unknown', $steps['PAPER entry']['status']);
    }

    public function test_it_does_not_infer_a_decision_from_a_non_passing_evaluation(): void
    {
        $opportunity = $this->opportunity();
        $evaluation = new TradingEngineOpportunityEvaluation([
            'outcome' => 'failed',
            'evaluated_at' => now()->subSecond(),
            'event_received_at' => now(),
            'projected_at' => now(),
        ]);
        $opportunity->setRelation('engineOpportunityEvaluations', new Collection([$evaluation]));

        $steps = $this->keyedSteps($opportunity);

        $this->assertSame('complete', $steps['Evaluation result received']['status']);
        $this->assertSame('unknown', $steps['PAPER decision']['status']);
        $this->assertSame('unknown', $steps['PAPER entry']['status']);
    }

    public function test_it_normalizes_unrecognized_entry_intent_status_to_unknown(): void
    {
        $opportunity = $this->opportunity();
        $intent = new TradingEnginePaperEntryIntent(['status' => 'future_state']);
        $intent->forceFill(['created_at' => now()]);
        $opportunity->setRelation('enginePaperEntryIntent', $intent);

        $steps = $this->keyedSteps($opportunity);

        $this->assertSame('complete', $steps['PAPER decision']['status']);
        $this->assertSame('unknown', $steps['PAPER entry']['status']);
        $this->assertStringContainsString('unrecognized', $steps['PAPER entry']['detail']);
    }

    public function test_acknowledgement_does_not_invent_a_separate_queue_acceptance_record(): void
    {
        $opportunity = $this->opportunity();
        $opportunity->setRelation('engineOpportunityLink', new TradingEngineOpportunityLink([
            'recorded_at' => now(),
            'linked_at' => now(),
        ]));

        $steps = $this->keyedSteps($opportunity);

        $this->assertSame('not_available', $steps['Engine export queued']['status']);
        $this->assertSame('complete', $steps['Engine export acknowledged']['status']);
    }

    /** @return array<string, array{label: string, status: string, detail: string, occurred_at: ?string}> */
    private function keyedSteps(TradeOpportunity $opportunity): array
    {
        return collect(app(TradingEngineOpportunityLifecyclePresenter::class)->present($opportunity))
            ->keyBy('label')
            ->all();
    }

    private function opportunity(): TradeOpportunity
    {
        $opportunity = new TradeOpportunity([
            'execution_mode' => ExecutionMode::Paper,
            'entry_mode' => EntryMode::Auto,
            'qualified_at' => now(),
        ]);
        $opportunity->setRelation('engineOpportunityLink', null);
        $opportunity->setRelation('engineOpportunityExport', null);
        $opportunity->setRelation('engineOpportunityEvaluations', new Collection);
        $opportunity->setRelation('systemActivities', new Collection);
        $opportunity->setRelation('enginePaperEntryIntent', null);
        $opportunity->setRelation('enginePaperPosition', null);
        $opportunity->setRelation('paperPosition', null);

        return $opportunity;
    }
}
