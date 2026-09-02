<?php

namespace Tests\Feature;

use App\Models\SystemActivity;
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

    public function test_recent_fast_cycle_is_active_and_becomes_stale(): void
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
        $this->assertSame('active', $status['status']);
        $this->assertSame(347.2, $status['cycle_duration_ms']);
        $this->assertSame(4, $status['open_positions']);
        $this->assertSame(3, $status['priced_positions']);

        $this->travel(6)->seconds();
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
}
