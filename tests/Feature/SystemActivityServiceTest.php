<?php

namespace Tests\Feature;

use App\Models\SystemActivity;
use App\Models\User;
use App\Services\PaperTrackerHealthService;
use App\Services\SystemActivityService;
use Tests\Concerns\RefreshesPaperTradingDatabase;
use Tests\TestCase;

class SystemActivityServiceTest extends TestCase
{
    use RefreshesPaperTradingDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshPaperTradingDatabase();
    }

    public function test_tracker_status_is_unknown_without_recorded_activity(): void
    {
        $status = app(SystemActivityService::class)->systemStatus();

        $this->assertSame('unknown', $status['status']);
        $this->assertNull($status['last_tracker_check']);
    }

    public function test_recent_scheduler_activity_does_not_claim_fast_tracker_is_active(): void
    {
        $this->travelTo('2026-09-01 08:00:00');
        SystemActivity::factory()->create([
            'triggered_by' => 'scheduler',
            'finished_at' => now()->subMinute(),
        ]);

        $status = app(SystemActivityService::class)->systemStatus();

        $this->assertSame('unknown', $status['status']);
        $this->assertNull($status['last_tracker_check']);
    }

    public function test_recent_partially_priced_cycle_is_degraded_and_becomes_stale(): void
    {
        $health = app(PaperTrackerHealthService::class);
        $health->recordCycle([
            'open_positions' => 4,
            'priced_positions' => 3,
            'provider_failures' => 1,
            'provider_requests' => 1,
            'rate_limited' => false,
        ], 347.2);

        $status = app(SystemActivityService::class)->systemStatus();
        $this->assertSame('degraded', $status['status']);
        $this->assertSame(347.2, $status['cycle_duration_ms']);
        $this->assertSame(4, $status['open_positions']);
        $this->assertSame(3, $status['priced_positions']);

        $this->travel(31)->seconds();
        $status = app(SystemActivityService::class)->systemStatus();

        $this->assertSame('stale', $status['status']);
    }

    public function test_current_activity_prioritizes_pending_or_running_manual_operation(): void
    {
        SystemActivity::factory()->create([
            'label' => 'Older completed manual',
            'triggered_by' => 'manual',
            'status' => 'completed',
        ]);
        SystemActivity::factory()->create([
            'label' => 'Scheduled Tracker',
            'triggered_by' => 'scheduler',
            'status' => 'running',
            'finished_at' => null,
        ]);
        SystemActivity::factory()->create([
            'label' => 'Run Momentum Scan',
            'triggered_by' => 'manual',
            'status' => 'pending',
            'started_at' => null,
            'finished_at' => null,
        ]);

        $current = app(SystemActivityService::class)->currentManualData();

        $this->assertSame('Run Momentum Scan', $current['label']);
        $this->assertSame('manual', $current['triggered_by']);
        $this->assertSame('pending', $current['status']);
    }

    public function test_scheduled_activity_does_not_become_current_manual_activity(): void
    {
        SystemActivity::factory()->create([
            'triggered_by' => 'scheduler',
            'status' => 'running',
            'finished_at' => null,
        ]);

        $service = app(SystemActivityService::class);
        $current = $service->currentManualData();

        $this->assertNull($current);
        $this->assertSame(['paper-track'], $service->runningActions());
    }

    public function test_obsolete_scheduled_scans_do_not_disable_manual_action_buttons(): void
    {
        SystemActivity::factory()->create([
            'action' => 'momentum-scan',
            'command' => 'tokens:momentum',
            'triggered_by' => 'scheduler',
            'status' => 'running',
            'finished_at' => null,
        ]);
        SystemActivity::factory()->create([
            'action' => 'token-scan',
            'command' => 'tokens:scan',
            'triggered_by' => 'manual',
            'status' => 'pending',
            'started_at' => null,
            'finished_at' => null,
        ]);

        $this->assertSame(['token-scan'], app(SystemActivityService::class)->runningActions());
    }

    public function test_recent_activity_includes_manual_scheduled_and_failed_results(): void
    {
        SystemActivity::factory()->create(['label' => 'Manual Result']);
        SystemActivity::factory()->create([
            'label' => 'Scheduled Result',
            'triggered_by' => 'scheduler',
        ]);
        SystemActivity::factory()->create([
            'label' => 'Failed Result',
            'status' => 'failed',
            'exit_code' => 1,
            'error_message' => 'Failure output',
        ]);

        $recent = app(SystemActivityService::class)->recentData();

        $this->assertCount(3, $recent);
        $this->assertSame('Failed Result', $recent[0]['label']);
        $this->assertSame('failed', $recent[0]['status']);
        $this->assertSame('scheduler', $recent[1]['triggered_by']);
        $this->assertSame('manual', $recent[2]['triggered_by']);
    }

    public function test_browser_presentation_never_exposes_raw_command_output_or_errors(): void
    {
        $activity = SystemActivity::factory()->create([
            'action' => 'momentum-scan',
            'status' => 'failed',
            'output' => 'provider response with secret token',
            'error_message' => 'database password was rejected',
        ]);

        $presented = app(SystemActivityService::class)->present($activity);

        $this->assertArrayNotHasKey('output', $presented);
        $this->assertArrayNotHasKey('error_message', $presented);
        $this->assertStringNotContainsString('secret', json_encode($presented, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('password', json_encode($presented, JSON_THROW_ON_ERROR));
        $this->assertSame(
            'The Laravel scanner command failed. Internal diagnostics were retained for operators.',
            $presented['summary'],
        );
        $this->assertSame('provider response with secret token', $activity->fresh()->output);
    }

    public function test_active_data_lists_all_visible_pending_and_running_work_for_the_user(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $other = User::factory()->create(['is_admin' => false]);
        SystemActivity::factory()->create([
            'user_id' => $user->id,
            'label' => 'USER SCAN',
            'action' => 'momentum-scan',
            'status' => 'pending',
            'started_at' => null,
            'finished_at' => null,
        ]);
        SystemActivity::factory()->create([
            'user_id' => $user->id,
            'label' => 'USER REPORT',
            'action' => 'paper-report',
            'status' => 'running',
            'finished_at' => null,
        ]);
        SystemActivity::factory()->create([
            'label' => 'SCHEDULED SCAN',
            'action' => 'token-scan',
            'triggered_by' => 'scheduler',
            'status' => 'running',
            'finished_at' => null,
        ]);
        SystemActivity::factory()->create([
            'user_id' => $other->id,
            'label' => 'OTHER USER',
            'status' => 'running',
            'finished_at' => null,
        ]);

        $activities = app(SystemActivityService::class)->activeData($user);

        $this->assertSame(['USER SCAN', 'USER REPORT'], array_column($activities, 'label'));
        $this->assertNotContains('OTHER USER', array_column($activities, 'label'));
        $this->assertNotContains('SCHEDULED SCAN', array_column($activities, 'label'));
    }

    public function test_scanner_overview_queries_are_scoped_to_the_authenticated_user(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $other = User::factory()->create(['is_admin' => false]);
        SystemActivity::factory()->create([
            'user_id' => $user->id,
            'action' => 'momentum-scan',
            'label' => 'USER MOMENTUM SCAN',
        ]);
        SystemActivity::factory()->create([
            'user_id' => $user->id,
            'action' => 'token-scan',
            'label' => 'LATEST USER SCAN',
        ]);
        SystemActivity::factory()->create([
            'user_id' => $other->id,
            'action' => 'token-scan',
            'label' => 'OTHER USER SCAN',
        ]);

        $service = app(SystemActivityService::class);

        $this->assertSame(2, $service->scannerCount($user));
        $this->assertSame('LATEST USER SCAN', $service->latestScannerData($user)['label']);
    }

    public function test_scanner_summary_availability_distinguishes_reported_zero_from_missing_counts(): void
    {
        $activity = SystemActivity::factory()->create([
            'action' => 'token-scan',
            'result_summary' => [
                'version' => 1,
                'kind' => 'scanner_execution',
                'scanner' => 'new-token',
                'chain' => 'solana',
                'outcome' => 'succeeded',
                'counts' => ['examined' => 0],
            ],
        ]);

        $summary = app(SystemActivityService::class)->present($activity)['result_summary'];

        $this->assertSame(0, $summary['counts']['examined']);
        $this->assertTrue($summary['availability']['counts']['examined']);
        $this->assertFalse($summary['availability']['counts']['qualified']);
        $this->assertFalse($summary['availability']['provider_failures']);
    }
}
