<?php

namespace Tests\Feature;

use App\Chain;
use App\Models\SystemActivity;
use App\Models\TradeOpportunity;
use App\Models\User;
use App\Services\ApplicationSettingsService;
use App\Services\EntryPolicy;
use App\Services\ScanExecutionRecorder;
use App\Services\SystemActivityService;
use App\Services\TradeOpportunityService;
use App\Services\TradingEngine\TradingEngineOpportunityLifecyclePresenter;
use DomainException;
use RuntimeException;
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
            $this->assertSame('The opportunity does not match an active scan activity boundary.', $exception->getMessage());
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

    public function test_late_correlation_is_rejected_after_the_scan_is_no_longer_active(): void
    {
        $user = User::factory()->create();
        $activity = $this->runningActivity($user, now());
        $recorder = app(ScanExecutionRecorder::class);
        $activityId = $recorder->begin($activity->getKey(), 'token-scan', 'new-token', Chain::Solana, $user);
        $opportunity = TradeOpportunity::factory()->for($user)->create([
            'chain' => Chain::Solana,
            'scanner' => 'new-token',
        ]);
        $activity->forceFill(['status' => 'completed', 'finished_at' => now(), 'exit_code' => 0])->save();
        $recorder->complete($activity, 0);

        try {
            $recorder->linkOpportunity($activityId, $opportunity);
            $this->fail('Expected late scan correlation to be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame('The opportunity does not match an active scan activity boundary.', $exception->getMessage());
        }

        $this->assertDatabaseMissing('system_activity_trade_opportunities', [
            'system_activity_id' => $activity->getKey(),
            'trade_opportunity_id' => $opportunity->getKey(),
        ]);
    }

    public function test_summary_separates_qualified_candidates_durable_creation_and_policy_failure(): void
    {
        app(ApplicationSettingsService::class)->update(['trading.entry_mode' => 'signal']);
        $user = User::factory()->create();
        $activity = $this->runningActivity($user, now());
        $recorder = app(ScanExecutionRecorder::class);
        $activityId = $recorder->begin($activity->getKey(), 'token-scan', 'new-token', Chain::Solana, $user);
        $recorder->examined($activityId);
        $recorder->qualifiedCandidate($activityId);
        $this->mock(EntryPolicy::class)
            ->shouldReceive('apply')
            ->once()
            ->andThrow(new RuntimeException('Internal policy detail must not be exposed.'));

        try {
            app(TradeOpportunityService::class)->qualify($this->candidate(), $user, $activityId);
            $this->fail('Expected the downstream policy failure.');
        } catch (RuntimeException) {
            $recorder->downstreamFailed($activityId, 'OPPORTUNITY_HANDLING_FAILED');
        }

        $activity->forceFill(['status' => 'completed', 'finished_at' => now(), 'exit_code' => 0])->save();
        $recorder->complete($activity, 0);
        $summary = $activity->fresh()->result_summary;

        $this->assertSame(1, $summary['counts']['qualified_candidates']);
        $this->assertSame(1, $summary['counts']['qualified']);
        $this->assertSame(1, $summary['counts']['opportunities_created']);
        $this->assertSame(0, $summary['counts']['opportunities_existing']);
        $this->assertSame(1, $summary['counts']['opportunities_correlated']);
        $this->assertSame(1, $summary['counts']['downstream_failed']);
        $this->assertSame(0, $summary['counts']['failed']);
        $this->assertSame(0, $summary['counts']['skipped']);
        $this->assertSame('succeeded_with_warnings', $summary['outcome']);
        $this->assertFalse($summary['zero_result']);
        $this->assertSame([
            ['code' => 'OPPORTUNITY_HANDLING_FAILED', 'count' => 1],
        ], $summary['downstream_failure_codes']);
        $this->assertStringNotContainsString('Internal policy detail', json_encode($summary, JSON_THROW_ON_ERROR));
    }

    public function test_existing_opportunity_is_counted_separately_when_newly_correlated(): void
    {
        app(ApplicationSettingsService::class)->update(['trading.entry_mode' => 'signal']);
        $user = User::factory()->create();
        $activity = $this->runningActivity($user, now());
        $recorder = app(ScanExecutionRecorder::class);
        $activityId = $recorder->begin($activity->getKey(), 'token-scan', 'new-token', Chain::Solana, $user);
        TradeOpportunity::factory()->for($user)->create([
            'chain' => Chain::Solana,
            'scanner' => 'new-token',
            'discovery_key' => str_repeat('c', 64),
        ]);
        $recorder->examined($activityId);
        $recorder->qualifiedCandidate($activityId);

        app(TradeOpportunityService::class)->qualify($this->candidate(), $user, $activityId);

        $summary = $activity->fresh()->result_summary;
        $this->assertSame(0, $summary['counts']['opportunities_created']);
        $this->assertSame(1, $summary['counts']['opportunities_existing']);
        $this->assertSame(1, $summary['counts']['opportunities_correlated']);
    }

    /** @return array<string, mixed> */
    private function candidate(): array
    {
        return [
            'chain' => 'solana',
            'address' => 'So11111111111111111111111111111111111111112',
            'symbol' => 'STAGEB',
            'name' => 'Stage B',
            'discovery_key' => str_repeat('c', 64),
            'discovery_market_cap' => 10_000,
            'entry_market_cap' => 12_000,
            'entry_price' => '0.00000125',
            'entry_liquidity' => 3_000,
            'volume' => 800,
            'move_since_discovery_percent' => 20,
            'scanner' => 'new-token',
            'send_notification' => false,
            'meta' => ['entry_source' => 'birdeye_provisional'],
        ];
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
