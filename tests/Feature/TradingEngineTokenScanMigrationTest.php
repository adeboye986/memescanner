<?php

namespace Tests\Feature;

use App\Models\SystemActivity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RefreshesPaperTradingDatabase;
use Tests\TestCase;

class TradingEngineTokenScanMigrationTest extends TestCase
{
    use RefreshesPaperTradingDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshPaperTradingDatabase();
    }

    public function test_historical_sqlite_rows_survive_the_additive_scanner_upgrade(): void
    {
        $migration = require database_path('migrations/2026_10_26_112431_create_trading_engine_scan_requests_table.php');
        $migration->down();

        $user = User::factory()->create();
        $activity = SystemActivity::query()->create([
            'action' => 'token-scan',
            'user_id' => $user->getKey(),
            'chain' => 'solana',
            'command' => 'tokens:scan',
            'label' => 'Historical scan',
            'status' => 'completed',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'duration_seconds' => 60,
            'exit_code' => 0,
            'output' => 'Historical scanner output',
            'error_message' => null,
            'triggered_by' => 'dashboard',
        ]);

        $migration->up();

        $activity->refresh();
        $this->assertSame('Historical scanner output', $activity->output);
        $this->assertSame('completed', $activity->status);
        $this->assertSame($user->getKey(), $activity->user_id);
        $this->assertSame('solana', $activity->chain->value);
        $this->assertNull($activity->result_summary);
        $this->assertNull($activity->last_progress_at);
        $this->assertTrue(User::query()->whereKey($user->getKey())->exists());
        $this->assertTrue(Schema::hasTable('trading_engine_scan_requests'));
        $this->assertTrue(Schema::hasColumn('trading_engine_scan_requests', 'acceptance_unknown_at'));
        $this->assertTrue(Schema::hasColumn('trading_engine_scan_requests', 'engine_scan_execution_id'));
        $this->assertDatabaseCount('trading_engine_scan_requests', 0);

        $indexes = collect(DB::select("PRAGMA index_list('trading_engine_scan_requests')"))
            ->pluck('name');
        $this->assertContains('te_scan_request_stale_index', $indexes);
        $this->assertContains('te_scan_request_recovery_index', $indexes);
    }
}
