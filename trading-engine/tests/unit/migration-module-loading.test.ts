import { promises as fs } from 'node:fs';
import path from 'node:path';

import { FileMigrationProvider } from 'kysely/migration';
import { describe, expect, it } from 'vitest';

describe('source migration module loading', () => {
  it('loads migration 003 through the production file migration provider', async () => {
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
    ]);
    const migration = migrations['003_opportunity_evaluations'];

    expect(typeof migration?.up).toBe('function');
    expect(typeof migration?.down).toBe('function');
  });
});
