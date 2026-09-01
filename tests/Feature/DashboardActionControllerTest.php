<?php

namespace Tests\Feature;

use App\Jobs\RunDashboardCommand;
use App\Models\SystemActivity;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\RefreshesPaperTradingDatabase;
use Tests\TestCase;

class DashboardActionControllerTest extends TestCase
{
    use RefreshesPaperTradingDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshPaperTradingDatabase();
    }

    public function test_allowed_action_creates_activity_and_dispatches_job(): void
    {
        Queue::fake([RunDashboardCommand::class]);

        $response = $this->post(route('dashboard.actions.store', 'momentum-scan'));

        $response
            ->assertRedirect()
            ->assertSessionHas('success', 'Run Momentum Scan was queued.');

        $activity = SystemActivity::query()->sole();

        $this->assertSame('momentum-scan', $activity->action);
        $this->assertSame('tokens:momentum', $activity->command);
        $this->assertSame('pending', $activity->status);
        $this->assertSame('manual', $activity->triggered_by);

        Queue::assertPushed(
            RunDashboardCommand::class,
            fn (RunDashboardCommand $job): bool => $job->activityId === $activity->id,
        );
    }

    public function test_unsupported_action_returns_404_without_dispatching_job(): void
    {
        Queue::fake([RunDashboardCommand::class]);

        $response = $this->post(route('dashboard.actions.store', 'config-cache'));

        $response->assertNotFound();
        $this->assertSame(0, SystemActivity::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_running_action_cannot_be_submitted_twice(): void
    {
        Queue::fake([RunDashboardCommand::class]);
        SystemActivity::factory()->create([
            'action' => 'paper-track',
            'status' => 'running',
            'finished_at' => null,
            'exit_code' => null,
        ]);

        $response = $this->post(route('dashboard.actions.store', 'paper-track'));

        $response
            ->assertRedirect()
            ->assertSessionHas('warning', 'Track Positions Now is already pending or running.');

        $this->assertSame(1, SystemActivity::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_action_route_rejects_request_without_csrf_token(): void
    {
        Queue::fake([RunDashboardCommand::class]);
        app()->detectEnvironment(fn (): string => 'production');

        $response = $this
            ->withMiddleware(PreventRequestForgery::class)
            ->post(route('dashboard.actions.store', 'token-scan'));

        $response->assertStatus(419);
        $this->assertSame(0, SystemActivity::query()->count());
        Queue::assertNothingPushed();
    }
}
