<?php

namespace Tests\Feature;

use App\Chain;
use App\Models\SystemActivity;
use App\Models\TradeOpportunity;
use App\Models\User;
use App\Services\ScanExecutionRecorder;
use App\Services\SystemActivityService;
use App\Services\TradingEngine\TradingEngineOpportunityLifecyclePresenter;
use DomainException;
use Tests\Concerns\RefreshesPaperTradingDatabase;
use Tests\TestCase;

class StageBScanCorrelationTest extends TestCase
{
    use RefreshesPaperTradingDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshPaperTradingDatabase();
    }

    public function test_activity_id_is_the_only_correlation_key_and_user_boundaries_are_enforced(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $sharedTimestamp = now()->subMinute();
        $selected = $this->runningActivity($owner, $sharedTimestamp);
        $unrelated = $this->runningActivity($owner, $sharedTimestamp);
        $recorder = app(ScanExecutionRecorder::class);
        $activityId = $recorder->begin($selected->getKey(), 'token-scan', 'new-token', Chain::Solana, $owner);
        $ownedOpportunity = TradeOpportunity::factory()->for($owner)->create([
            'chain' => Chain::Solana,
            'scanner' => 'new-token',
        ]);

        $recorder->linkOpportunity($activityId, $ownedOpportunity);

        $this->assertDatabaseHas('system_activity_trade_opportunities', [
            'system_activity_id' => $selected->getKey(),
            'trade_opportunity_id' => $ownedOpportunity->getKey(),
        ]);
        $this->assertDatabaseMissing('system_activity_trade_opportunities', [
            'system_activity_id' => $unrelated->getKey(),
            'trade_opportunity_id' => $ownedOpportunity->getKey(),
        ]);

        $otherOpportunity = TradeOpportunity::factory()->for($otherUser)->create([
            'chain' => Chain::Solana,
            'scanner' => 'new-token',
        ]);

        try {
            $recorder->linkOpportunity($activityId, $otherOpportunity);
            $this->fail('Expected cross-user scan correlation to be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame('The opportunity does not match the scan activity boundary.', $exception->getMessage());
        }

        $this->assertDatabaseMissing('system_activity_trade_opportunities', [
            'system_activity_id' => $selected->getKey(),
            'trade_opportunity_id' => $otherOpportunity->getKey(),
        ]);
    }

    public function test_activity_visibility_remains_isolated_by_user(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $activity = $this->runningActivity($owner, now());

        $ownerIds = collect(app(SystemActivityService::class)->activeData($owner))->pluck('id');
        $otherIds = collect(app(SystemActivityService::class)->activeData($otherUser))->pluck('id');

        $this->assertTrue($ownerIds->contains($activity->getKey()));
        $this->assertFalse($otherIds->contains($activity->getKey()));
    }

    public function test_historical_records_without_stage_b_evidence_remain_explicitly_unknown(): void
    {
        $user = User::factory()->create();
        $activity = SystemActivity::factory()->for($user)->create([
            'action' => 'token-scan',
            'chain' => 'solana',
            'command' => 'tokens:scan',
            'label' => 'Historical Token Scan',
            'result_summary' => null,
            'last_progress_at' => null,
        ]);
        $presentedActivity = app(SystemActivityService::class)->present($activity);

        $this->assertNull($presentedActivity['result_summary']);
        $this->assertStringContainsString('No structured per-run evidence is available', $presentedActivity['summary']);

        $opportunity = TradeOpportunity::factory()->for($user)->create([
            'chain' => Chain::Solana,
            'scanner' => 'new-token',
        ])->load([
            'paperPosition',
            'engineOpportunityLink',
            'engineOpportunityExport',
            'engineOpportunityEvaluations',
            'enginePaperEntryIntent',
            'enginePaperPosition',
            'systemActivities',
        ]);
        $steps = collect(app(TradingEngineOpportunityLifecyclePresenter::class)->present($opportunity))
            ->keyBy('label');

        $this->assertSame('unknown', $steps['Dashboard scan correlation']['status']);
        $this->assertSame('not_available', $steps['Engine export queued']['status']);
        $this->assertSame('unknown', $steps['Engine export acknowledged']['status']);
    }

    private function runningActivity(User $user, \DateTimeInterface $timestamp): SystemActivity
    {
        return SystemActivity::factory()->for($user)->create([
            'action' => 'token-scan',
            'chain' => 'solana',
            'command' => 'tokens:scan',
            'label' => 'Run Token Scan - Solana',
            'status' => 'running',
            'started_at' => $timestamp,
            'finished_at' => null,
            'duration_seconds' => null,
            'exit_code' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }
}
