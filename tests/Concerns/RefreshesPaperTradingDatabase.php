<?php

namespace Tests\Concerns;

trait RefreshesPaperTradingDatabase
{
    protected function refreshPaperTradingDatabase(): void
    {
        $this->artisan('migrate:fresh', [
            '--path' => [
                'database/migrations/0001_01_01_000000_create_users_table.php',
                'database/migrations/2026_08_31_000001_create_paper_positions_table.php',
                'database/migrations/2026_08_31_000002_create_paper_position_snapshots_table.php',
                'database/migrations/2026_08_31_000003_add_exit_strategy_to_paper_positions_table.php',
                'database/migrations/2026_08_31_093657_create_paper_wallets_table.php',
                'database/migrations/2026_08_31_094345_add_virtual_sol_trade_fields_to_paper_positions_table.php',
                'database/migrations/2026_09_01_063830_create_system_activities_table.php',
                'database/migrations/2026_09_01_142816_add_chain_to_trading_tables.php',
                'database/migrations/2026_09_02_124658_add_chain_and_currency_to_paper_wallets_table.php',
                'database/migrations/2026_09_02_145244_create_paper_strategy_settings_table.php',
                'database/migrations/2026_09_02_145245_add_strategy_snapshot_to_paper_positions_table.php',
                'database/migrations/2026_09_02_211901_create_application_settings_table.php',
                'database/migrations/2026_09_02_211902_create_setting_audits_table.php',
                'database/migrations/2026_09_02_211903_create_trade_opportunities_table.php',
                'database/migrations/2026_09_02_211904_add_is_admin_to_users_table.php',
                'database/migrations/2026_09_02_215918_create_trade_opportunity_events_table.php',
            ],
            '--no-interaction' => true,
        ])->assertSuccessful();
    }
}
