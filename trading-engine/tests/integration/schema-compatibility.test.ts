import { sql, type Kysely } from 'kysely';
import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest';

import { SOLANA_MAINNET_ID } from '../../src/contracts/http/opportunity-command.schema.js';
import { createDatabase, type Database } from '../../src/infrastructure/database/client.js';
import { inspectDatabaseSchemaCompatibility } from '../../src/infrastructure/database/schema-compatibility.js';
import { newEngineId } from '../../src/shared/ids/id.js';
import {
  createTestIdentity,
  migrateTestDatabaseTo,
  resetDatabaseSchema,
  startTestEnvironment,
  stopTestEnvironment,
  type TestEnvironment,
} from '../support/test-environment.js';

const migration006 = '006_paper_financial_lifecycle';
const migration007 = '007_paper_market_monitoring';
const migration008 = '008_paper_market_shadow_observations';

const disabledFeatures = {
  paperEntryEnabled: false,
  paperMarketMonitoringEnabled: false,
  paperMarketMonitorCanaryUserIds: [],
} as const;

const entryEnabled = {
  ...disabledFeatures,
  paperEntryEnabled: true,
} as const;

const monitoringEnabled = {
  ...entryEnabled,
  paperMarketMonitoringEnabled: true,
  paperMarketMonitorCanaryUserIds: ['1'],
} as const;

describe('database schema compatibility and monitoring migration upgrades', () => {
  let environment: TestEnvironment;
  let database: Kysely<Database>;

  beforeAll(async () => {
    environment = await startTestEnvironment();
    database = createDatabase(createTestIdentity(environment).config);
  });

  beforeEach(async () => {
    await resetDatabaseSchema(database);
  });

  afterAll(async () => {
    await database.destroy();
    await stopTestEnvironment(environment);
  });

  it('detects the supported 006, 007, and 008 capability boundaries', async () => {
    await migrateTestDatabaseTo(database, migration006);

    await expect(inspectDatabaseSchemaCompatibility(database, disabledFeatures))
      .resolves.toMatchObject({
        compatible: true,
        detectedVersion: 6,
        requiredVersion: 6,
      });
    await expect(inspectDatabaseSchemaCompatibility(database, entryEnabled))
      .resolves.toMatchObject({
        compatible: false,
        detectedVersion: 6,
        requiredVersion: 7,
      });

    await migrateTestDatabaseTo(database, migration007);

    await expect(inspectDatabaseSchemaCompatibility(database, entryEnabled))
      .resolves.toMatchObject({
        compatible: true,
        detectedVersion: 7,
        requiredVersion: 7,
      });
    await expect(inspectDatabaseSchemaCompatibility(database, monitoringEnabled))
      .resolves.toMatchObject({
        compatible: false,
        detectedVersion: 7,
        requiredVersion: 8,
      });

    await migrateTestDatabaseTo(database, migration008);

    await expect(inspectDatabaseSchemaCompatibility(database, monitoringEnabled))
      .resolves.toEqual({
        compatible: true,
        detectedVersion: 8,
        requiredVersion: 8,
        missingCapabilities: [],
      });
  });

  it('upgrades 006 through 008 without mutating financial evidence or backfilling monitoring', async () => {
    await migrateTestDatabaseTo(database, migration006);
    const walletId = newEngineId();
    const transactionId = newEngineId();
    const availableEntryId = newEngineId();
    const equityEntryId = newEngineId();
    const occurredAt = new Date('2026-10-11T10:00:00.000Z');

    await database.transaction().execute(async (transaction) => {
      await transaction.insertInto('paper_wallets').values({
        id: walletId,
        control_plane_user_id: 'existing-user',
        network_id: SOLANA_MAINNET_ID,
        currency: 'SOL',
        opening_balance_native: '5',
        opening_reference: 'existing-opening-balance',
      }).execute();
      await transaction.insertInto('paper_ledger_transactions').values({
        id: transactionId,
        wallet_id: walletId,
        transaction_type: 'opening_balance',
        reference_id: 'existing-opening-ledger',
        idempotency_key: 'existing-opening-idempotency',
        correlation_id: 'existing-correlation',
        occurred_at: occurredAt,
      }).execute();
      await transaction.insertInto('paper_ledger_entries').values([
        {
          id: availableEntryId,
          transaction_id: transactionId,
          wallet_id: walletId,
          account: 'available',
          direction: 'debit',
          amount_native: '5',
          currency: 'SOL',
        },
        {
          id: equityEntryId,
          transaction_id: transactionId,
          wallet_id: walletId,
          account: 'opening_equity',
          direction: 'credit',
          amount_native: '5',
          currency: 'SOL',
        },
      ]).execute();
    });

    const beforeUpgrade = await financialEvidence(database, walletId, transactionId);

    await migrateTestDatabaseTo(database, migration007);

    expect(await financialEvidence(database, walletId, transactionId))
      .toEqual(beforeUpgrade);
    expect(await tableCount(database, 'paper_position_monitoring_tasks')).toBe(0);

    await migrateTestDatabaseTo(database, migration008);

    expect(await financialEvidence(database, walletId, transactionId))
      .toEqual(beforeUpgrade);
    expect(await tableCount(database, 'paper_position_monitoring_tasks')).toBe(0);
    expect(await tableCount(database, 'paper_market_shadow_observations')).toBe(0);

    const schemaObjects = await sql<{
      readonly constraints: number;
      readonly indexes: number;
      readonly triggers: number;
    }>`
      select
        (
          select count(*)::int
          from pg_constraint
          where conname = 'paper_market_shadow_observations_position_attempt_unique'
            and conrelid = to_regclass('public.paper_market_shadow_observations')
        ) as constraints,
        (
          select count(*)::int
          from pg_indexes
          where schemaname = 'public'
            and indexname in (
              'paper_market_shadow_observations_position_created_index',
              'paper_market_shadow_observations_request_index',
              'paper_market_shadow_observations_outcome_created_index'
            )
        ) as indexes,
        (
          select count(*)::int
          from pg_trigger
          where tgname = 'paper_market_shadow_observations_append_only'
            and tgrelid = to_regclass('public.paper_market_shadow_observations')
            and not tgisinternal
        ) as triggers
    `.execute(database);

    expect(schemaObjects.rows[0]).toEqual({
      constraints: 1,
      indexes: 3,
      triggers: 1,
    });
  });

  it('fails compatibility when migration history exists but a required schema object is absent', async () => {
    await migrateTestDatabaseTo(database, migration008);
    await sql`
      drop index paper_market_shadow_observations_request_index
    `.execute(database);

    const compatibility = await inspectDatabaseSchemaCompatibility(
      database,
      monitoringEnabled,
    );

    expect(compatibility).toMatchObject({
      compatible: false,
      detectedVersion: 8,
      requiredVersion: 8,
      missingCapabilities: ['PAPER_SHADOW_OBSERVATION_SCHEMA_UNAVAILABLE'],
    });
  });
});

async function financialEvidence(
  database: Kysely<Database>,
  walletId: string,
  transactionId: string,
): Promise<{
  readonly wallet: unknown;
  readonly transaction: unknown;
  readonly entries: readonly unknown[];
}> {
  const wallet = await database.selectFrom('paper_wallets')
    .selectAll()
    .where('id', '=', walletId)
    .executeTakeFirstOrThrow();
  const transaction = await database.selectFrom('paper_ledger_transactions')
    .selectAll()
    .where('id', '=', transactionId)
    .executeTakeFirstOrThrow();
  const entries = await database.selectFrom('paper_ledger_entries')
    .selectAll()
    .where('transaction_id', '=', transactionId)
    .orderBy('id', 'asc')
    .execute();

  return { wallet, transaction, entries };
}

async function tableCount(
  database: Kysely<Database>,
  table: 'paper_position_monitoring_tasks' | 'paper_market_shadow_observations',
): Promise<number> {
  const result = await database.selectFrom(table)
    .select(sql.raw<number>('count(*)::int').as('count'))
    .executeTakeFirstOrThrow();

  return result.count;
}
