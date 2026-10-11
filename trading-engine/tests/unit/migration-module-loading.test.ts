import { promises as fs } from 'node:fs';
import path from 'node:path';

import { FileMigrationProvider } from 'kysely/migration';
import { describe, expect, it, vi } from 'vitest';

describe('source migration module loading', () => {
  it('loads the database-only migration CLI without executing it on import', async () => {
    const module = await import('../../apps/migrate/src/main.js');

    expect(typeof module.runDatabaseMigrationCli).toBe('function');
  });

  it('runs migrations only through the dedicated database CLI', async () => {
    const module = await import('../../apps/migrate/src/main.js');
    const migrationRunner = vi.fn(() => Promise.resolve({ results: [] }));

    await module.runDatabaseMigrationCli({
      DATABASE_URL: 'postgresql://test@127.0.0.1:5432/trading_engine_test',
      DATABASE_SSL: 'false',
      DATABASE_MAX_CONNECTIONS: '2',
    }, migrationRunner);

    expect(migrationRunner).toHaveBeenCalledOnce();
    expect(migrationRunner).toHaveBeenCalledWith({
      databaseUrl: 'postgresql://test@127.0.0.1:5432/trading_engine_test',
      databaseSsl: false,
      databaseCaCertificate: undefined,
      databaseMaxConnections: 2,
    });
  });

  it('loads all migrations through the production file migration provider', async () => {
    const provider = new FileMigrationProvider({
      fs,
      path,
      migrationFolder: path.resolve('src/infrastructure/database/migrations'),
    });

    const migrations = await provider.getMigrations();

    expect(Object.keys(migrations)).toEqual([
      '001_foundation',
      '002_opportunities',
      '003_opportunity_evaluations',
      '004_paper_position_lifecycle',
      '005_paper_financial_entry',
      '006_paper_financial_lifecycle',
      '007_paper_market_monitoring',
      '008_paper_market_shadow_observations',
    ]);
    const migration = migrations['003_opportunity_evaluations'];
    const paperLifecycleMigration = migrations['004_paper_position_lifecycle'];
    const paperFinancialEntryMigration = migrations['005_paper_financial_entry'];
    const paperFinancialLifecycleMigration = migrations['006_paper_financial_lifecycle'];
    const paperMarketMonitoringMigration = migrations['007_paper_market_monitoring'];
    const paperMarketShadowMigration = migrations['008_paper_market_shadow_observations'];

    expect(typeof migration?.up).toBe('function');
    expect(typeof migration?.down).toBe('function');
    expect(typeof paperLifecycleMigration?.up).toBe('function');
    expect(typeof paperLifecycleMigration?.down).toBe('function');
    expect(typeof paperFinancialEntryMigration?.up).toBe('function');
    expect(typeof paperFinancialEntryMigration?.down).toBe('function');
    expect(typeof paperFinancialLifecycleMigration?.up).toBe('function');
    expect(typeof paperFinancialLifecycleMigration?.down).toBe('function');
    expect(typeof paperMarketMonitoringMigration?.up).toBe('function');
    expect(typeof paperMarketMonitoringMigration?.down).toBe('function');
    expect(typeof paperMarketShadowMigration?.up).toBe('function');
    expect(typeof paperMarketShadowMigration?.down).toBe('function');
  });
});
