<?php

namespace Tests\Feature;

use App\Models\SystemActivity;
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

    public function test_recent_successful_scheduled_tracker_is_active(): void
    {
        $this->travelTo('2026-09-01 08:00:00');
        SystemActivity::factory()->create([
            'triggered_by' => 'scheduler',
            'finished_at' => now()->subMinute(),
        ]);

        $status = app(SystemActivityService::class)->systemStatus();

        $this->assertSame('active', $status['status']);
        $this->assertTrue($status['last_tracker_check']->equalTo(now()->subMinute()));
    }

    public function test_failed_tracker_is_stale_even_when_recent(): void
    {
        SystemActivity::factory()->create([
            'status' => 'failed',
            'finished_at' => now(),
            'exit_code' => 1,
        ]);

        $status = app(SystemActivityService::class)->systemStatus();

        $this->assertSame('stale', $status['status']);
    }
}
