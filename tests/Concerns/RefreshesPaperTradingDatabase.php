<?php

namespace Tests\Concerns;

trait RefreshesPaperTradingDatabase
{
    protected function refreshPaperTradingDatabase(): void
    {
        $this->artisan('migrate:fresh', [
            '--path' => [
                'database/migrations/2026_08_31_000001_create_paper_positions_table.php',
                'database/migrations/2026_08_31_000002_create_paper_position_snapshots_table.php',
                'database/migrations/2026_08_31_000003_add_exit_strategy_to_paper_positions_table.php',
                'database/migrations/2026_08_31_093657_create_paper_wallets_table.php',
                'database/migrations/2026_08_31_094345_add_virtual_sol_trade_fields_to_paper_positions_table.php',
                'database/migrations/2026_09_01_063830_create_system_activities_table.php',
                'database/migrations/2026_09_01_142816_add_chain_to_trading_tables.php',
            ],
            '--no-interaction' => true,
        ])->assertSuccessful();
    }
}
