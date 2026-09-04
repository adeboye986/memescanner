<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OperationalHealthService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RefreshesPaperTradingDatabase;
use Tests\TestCase;

class OperationalHealthTest extends TestCase
{
    use RefreshesPaperTradingDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshPaperTradingDatabase();
        Cache::store('file')->forget('operations.scheduler.last_run');
        Cache::store('file')->forget('operations.queue.health');
        Schema::create('jobs', function ($table): void {
            $table->id();
            $table->string('queue');
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::create('failed_jobs', function ($table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at');
        });
    }

    public function test_operational_heartbeats_report_never_run_then_healthy(): void
    {
        $health = app(OperationalHealthService::class);
        $this->assertSame('never_run', $health->status()['scheduler']['status']);
        $this->assertSame('never_run', $health->status()['queue']['status']);

        $health->recordSchedulerRun();
        $health->recordQueueRun('running');
        $health->recordQueueJobProcessed();
        $health->recordQueueRun('completed', 1);
        $status = $health->status();

        $this->assertSame('healthy', $status['scheduler']['status']);
        $this->assertSame('healthy', $status['queue']['status']);
        $this->assertSame(1, $status['queue']['processed_jobs']);
        $this->assertNotNull($status['queue']['last_job_processed_at']);
    }

    public function test_stale_heartbeats_and_safe_job_counts_are_reported(): void
    {
        Cache::store('file')->forever('operations.scheduler.last_run', now()->subHour()->toIso8601String());
        Cache::store('file')->forever('operations.queue.health', ['last_run_at' => now()->subHour()->toIso8601String()]);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp]);
        DB::table('failed_jobs')->insert(['uuid' => fake()->uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'safe-test', 'failed_at' => now()]);

        $status = app(OperationalHealthService::class)->status();

        $this->assertSame('stale', $status['scheduler']['status']);
        $this->assertSame('stale', $status['queue']['status']);
        $this->assertSame(1, $status['pending_jobs']);
        $this->assertSame(1, $status['failed_jobs']);
        $this->artisan('app:health')->expectsOutputToContain('Scheduler')->doesntExpectOutputToContain('safe-test');
    }

    public function test_queue_drain_is_bounded_updates_heartbeat_and_prevents_overlap(): void
    {
        $this->artisan('app:queue-drain', ['--max-time' => 5])->assertSuccessful();
        $this->assertSame('healthy', app(OperationalHealthService::class)->status()['queue']['status']);

        $lock = Cache::store('file')->lock('operations.queue-drain', 120);
        $this->assertTrue($lock->get());
        try {
            $this->artisan('app:queue-drain')->expectsOutputToContain('already running')->assertSuccessful();
        } finally {
            $lock->release();
        }
    }

    public function test_scheduled_heartbeat_event_updates_scheduler_health(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => $event->description === 'operations.scheduler-heartbeat');

        $this->assertNotNull($event);
        $event->run(app());

        $this->assertSame('healthy', app(OperationalHealthService::class)->status()['scheduler']['status']);
    }

    public function test_admin_dashboard_shows_stale_operations_without_exposing_secret_values(): void
    {
        Cache::store('file')->forever('operations.scheduler.last_run', now()->subHour()->toIso8601String());
        Cache::store('file')->forever('operations.queue.health', ['last_run_at' => now()->subHour()->toIso8601String()]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Background Process Health')
            ->assertSee('STALE')
            ->assertDontSee('a-secure-webhook-secret');
    }
}
