<?php

namespace Tests\Feature;

use App\Jobs\RunDashboardCommand;
use App\Models\PaperWallet;
use App\Models\SystemActivity;
use App\Services\DashboardCommandRegistry;
use App\Services\SystemActivityService;
use Tests\Concerns\RefreshesPaperTradingDatabase;
use Tests\TestCase;

class RunDashboardCommandTest extends TestCase
{
    use RefreshesPaperTradingDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshPaperTradingDatabase();
    }

    public function test_successful_command_records_completion_and_output(): void
    {
        $this->createWallet();
        $activity = $this->createPendingActivity('paper-report');

        $this->runJob($activity);

        $activity->refresh();

        $this->assertSame('completed', $activity->status);
        $this->assertSame(0, $activity->exit_code);
        $this->assertNotNull($activity->started_at);
        $this->assertNotNull($activity->finished_at);
        $this->assertStringContainsString('PAPER TRADING REPORT', $activity->output);
    }

    public function test_failed_command_records_failure_and_exit_code(): void
    {
        $activity = $this->createPendingActivity('paper-report');

        $this->runJob($activity);

        $activity->refresh();

        $this->assertSame('failed', $activity->status);
        $this->assertSame(1, $activity->exit_code);
        $this->assertSame('Command exited with code 1.', $activity->error_message);
        $this->assertStringContainsString('Default Solana paper wallet not found.', $activity->output);
    }

    public function test_reconciliation_command_never_applies_fix_option(): void
    {
        $wallet = $this->createWallet(['available_balance_sol' => 3]);
        $activity = $this->createPendingActivity('paper-reconcile');

        $this->runJob($activity);

        $activity->refresh();

        $this->assertSame(3.0, $wallet->fresh()->available_balance_sol);
        $this->assertSame('completed', $activity->status);
        $this->assertStringContainsString('Wallet does NOT match', $activity->output);
        $this->assertStringContainsString('Run php artisan tokens:paper-reconcile --chain=solana --fix', $activity->output);
    }

    /** @param array<string, mixed> $attributes */
    private function createWallet(array $attributes = []): PaperWallet
    {
        return PaperWallet::query()->create(array_merge([
            'name' => 'default',
            'starting_balance_sol' => 5,
            'available_balance_sol' => 5,
            'invested_balance_sol' => 0,
            'realized_pnl_sol' => 0,
        ], $attributes));
    }

    private function createPendingActivity(string $action): SystemActivity
    {
        $definition = app(DashboardCommandRegistry::class)->get($action);

        return SystemActivity::factory()->create([
            'action' => $action,
            'command' => $definition['command'],
            'label' => $definition['label'],
            'status' => 'pending',
            'started_at' => null,
            'finished_at' => null,
            'duration_seconds' => null,
            'exit_code' => null,
            'output' => null,
            'triggered_by' => 'manual',
        ]);
    }

    private function runJob(SystemActivity $activity): void
    {
        (new RunDashboardCommand($activity->id))->handle(
            app(DashboardCommandRegistry::class),
            app(SystemActivityService::class),
        );
    }
}
