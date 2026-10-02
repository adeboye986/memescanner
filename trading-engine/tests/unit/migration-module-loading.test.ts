import { promises as fs } from 'node:fs';
import path from 'node:path';

import { FileMigrationProvider } from 'kysely/migration';
import { describe, expect, it } from 'vitest';

describe('source migration module loading', () => {
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
    ]);
    const migration = migrations['003_opportunity_evaluations'];
    const paperLifecycleMigration = migrations['004_paper_position_lifecycle'];

    expect(typeof migration?.up).toBe('function');
    expect(typeof migration?.down).toBe('function');
    expect(typeof paperLifecycleMigration?.up).toBe('function');
    expect(typeof paperLifecycleMigration?.down).toBe('function');
  });
});
