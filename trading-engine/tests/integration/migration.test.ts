import { sql, type Kysely } from 'kysely';
import { Redis } from 'ioredis';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';

import { createDatabase, type Database } from '../../src/infrastructure/database/client.js';
import { migrateDatabase } from '../../src/infrastructure/database/migrate.js';
import {
  assertDedicatedTestDatabase,
  createTestIdentity,
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

  it('applies the foundation migration to an empty PostgreSQL database and reruns safely', async () => {
    await assertDedicatedTestDatabase(database);
    await database.schema.dropTable('opportunities').ifExists().cascade().execute();
    await database.schema.dropTable('event_delivery_attempts').ifExists().cascade().execute();
    await database.schema.dropTable('event_outbox').ifExists().cascade().execute();
    await database.schema.dropTable('command_inbox').ifExists().cascade().execute();
    await database.schema.dropTable('kysely_migration').ifExists().cascade().execute();
    await database.schema.dropTable('kysely_migration_lock').ifExists().cascade().execute();

    const first = await migrateDatabase(database);
    const second = await migrateDatabase(database);
    const tables = await sql.raw<{ readonly table_name: string }>("select table_name from information_schema.tables where table_schema = 'public' and table_name in ('command_inbox', 'event_outbox', 'event_delivery_attempts', 'opportunities') order by table_name").execute(database);

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
    ]);
    expect(second.error).toBeUndefined();
    expect(second.results).toEqual([]);
    expect(tables.rows.map((table) => table.table_name)).toEqual([
      'command_inbox',
      'event_delivery_attempts',
      'event_outbox',
      'opportunities',
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
