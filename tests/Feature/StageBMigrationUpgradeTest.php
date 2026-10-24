<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RefreshesPaperTradingDatabase;
use Tests\TestCase;

class StageBMigrationUpgradeTest extends TestCase
{
    use RefreshesPaperTradingDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshPaperTradingDatabase();
    }

    public function test_historical_sqlite_rows_survive_the_additive_stage_b_upgrade(): void
    {
        $structured = require database_path('migrations/2026_10_23_153837_add_structured_results_to_system_activities_table.php');
        $correlations = require database_path('migrations/2026_10_23_153841_create_system_activity_trade_opportunities_table.php');
        $exports = require database_path('migrations/2026_10_23_153845_create_trading_engine_opportunity_exports_table.php');

        $exports->down();
        $correlations->down();
        $structured->down();

        $activityId = DB::table('system_activities')->insertGetId([
            'action' => 'token-scan',
            'user_id' => null,
            'chain' => 'solana',
            'command' => 'tokens:scan',
            'label' => 'Historical scan',
            'status' => 'completed',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'duration_seconds' => 60,
            'exit_code' => 0,
            'output' => 'Historical output',
            'error_message' => null,
            'triggered_by' => 'scheduler',
            'created_at' => now()->subMinute(),
            'updated_at' => now(),
        ]);

        $structured->up();
        $correlations->up();
        $exports->up();

        $activity = DB::table('system_activities')->where('id', $activityId)->sole();
        $this->assertSame('Historical output', $activity->output);
        $this->assertNull($activity->result_summary);
        $this->assertNull($activity->last_progress_at);
        $this->assertTrue(Schema::hasTable('system_activity_trade_opportunities'));
        $this->assertTrue(Schema::hasTable('trading_engine_opportunity_exports'));
        $this->assertTrue(Schema::hasColumn('system_activities', 'result_summary'));
        $this->assertTrue(Schema::hasColumn('system_activities', 'last_progress_at'));

        $activityIndexes = collect(DB::select("PRAGMA index_list('system_activities')"))->pluck('name');
        $exportIndexes = collect(DB::select("PRAGMA index_list('trading_engine_opportunity_exports')"))->pluck('name');
        $this->assertContains('system_activities_status_progress_idx', $activityIndexes);
        $this->assertContains('system_activities_action_status_progress_idx', $activityIndexes);
        $this->assertContains('trading_engine_opportunity_exports_status_updated_idx', $exportIndexes);
    }
}
