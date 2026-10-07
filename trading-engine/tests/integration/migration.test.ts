import { sql, type Kysely } from 'kysely';
import { Redis } from 'ioredis';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';

import { createDatabase, type Database } from '../../src/infrastructure/database/client.js';
import { migrateDatabase } from '../../src/infrastructure/database/migrate.js';
import {
  createTestIdentity,
  resetDatabaseSchema,
  startTestEnvironment,
  stopTestEnvironment,
  type TestEnvironment,
} from '../support/test-environment.js';

describe('foundation migration and infrastructure connectivity', () => {
  let environment: TestEnvironment;
  let database: Kysely<Database>;

  beforeAll(async () => {
    environment = await startTestEnvironment();
    database = createDatabase(createTestIdentity(environment).config);
  });

  afterAll(async () => {
    await database.destroy();
    await stopTestEnvironment(environment);
  });

  it('applies all migrations to an empty PostgreSQL database and reruns safely', async () => {
    await resetDatabaseSchema(database);

    const first = await migrateDatabase(database);
    const second = await migrateDatabase(database);
    const tables = await sql.raw<{ readonly table_name: string }>("select table_name from information_schema.tables where table_schema = 'public' and table_name in ('command_inbox', 'event_outbox', 'event_delivery_attempts', 'opportunities', 'evaluation_policies', 'opportunity_evaluation_tasks', 'opportunity_evaluations', 'paper_position_lifecycles', 'paper_position_lifecycle_decisions', 'paper_wallets', 'paper_ledger_transactions', 'paper_ledger_entries', 'paper_entry_intents', 'paper_orders', 'paper_fills', 'paper_positions') order by table_name").execute(database);

    expect(first.error).toBeUndefined();
    expect(first.results).toEqual([
      expect.objectContaining({
        migrationName: '001_foundation',
        status: 'Success',
      }),
      expect.objectContaining({
        migrationName: '002_opportunities',
        status: 'Success',
      }),
      expect.objectContaining({
        migrationName: '003_opportunity_evaluations',
        status: 'Success',
      }),
      expect.objectContaining({
        migrationName: '004_paper_position_lifecycle',
        status: 'Success',
      }),
      expect.objectContaining({
        migrationName: '005_paper_financial_entry',
        status: 'Success',
      }),
    ]);
    expect(second.error).toBeUndefined();
    expect(second.results).toEqual([]);
    expect(tables.rows.map((table) => table.table_name)).toEqual([
      'command_inbox',
      'evaluation_policies',
      'event_delivery_attempts',
      'event_outbox',
      'opportunities',
      'opportunity_evaluation_tasks',
      'opportunity_evaluations',
      'paper_entry_intents',
      'paper_fills',
      'paper_ledger_entries',
      'paper_ledger_transactions',
      'paper_orders',
      'paper_position_lifecycle_decisions',
      'paper_position_lifecycles',
      'paper_positions',
      'paper_wallets',
    ]);
  });

  it('connects to externalizable Redis configuration', async () => {
    const redis = new Redis(environment.redisUrl, {
      lazyConnect: true,
      maxRetriesPerRequest: 1,
    });

    await redis.connect();
    const response = await redis.ping();
    await redis.quit();

    expect(response).toBe('PONG');
  });
});
