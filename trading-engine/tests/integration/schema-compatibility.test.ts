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
const migration009 = '009_paper_market_observation_authority';

const disabledFeatures = {
  paperEntryEnabled: false,
  paperMarketMonitoringEnabled: false,
  paperMarketMonitoringAuthorityEnabled: false,
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

const authoritativeMonitoringEnabled = {
  ...monitoringEnabled,
  paperMarketMonitoringAuthorityEnabled: true,
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

  it('keeps disabled schema 006 compatible and requires 009 for entry or monitoring', async () => {
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
        requiredVersion: 9,
      });

    await migrateTestDatabaseTo(database, migration007);
    await expect(inspectDatabaseSchemaCompatibility(database, entryEnabled))
      .resolves.toMatchObject({
        compatible: false,
        detectedVersion: 7,
        requiredVersion: 9,
      });

    await migrateTestDatabaseTo(database, migration008);
    await expect(inspectDatabaseSchemaCompatibility(database, monitoringEnabled))
      .resolves.toMatchObject({
        compatible: false,
        detectedVersion: 8,
        requiredVersion: 9,
        missingCapabilities: [
          'MIGRATION_009_NOT_APPLIED',
          'PAPER_MARKET_AUTHORITY_SCHEMA_UNAVAILABLE',
        ],
      });

    await migrateTestDatabaseTo(database, migration009);

    await expect(inspectDatabaseSchemaCompatibility(database, monitoringEnabled))
      .resolves.toEqual({
        compatible: true,
        detectedVersion: 9,
        requiredVersion: 9,
        missingCapabilities: [],
      });
    await expect(inspectDatabaseSchemaCompatibility(database, authoritativeMonitoringEnabled))
      .resolves.toEqual({
        compatible: true,
        detectedVersion: 9,
        requiredVersion: 9,
        missingCapabilities: [],
      });
  });

  it('upgrades 006 through 009 without mutating financial evidence or backfilling monitoring', async () => {
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
    await migrateTestDatabaseTo(database, migration008);
    await migrateTestDatabaseTo(database, migration009);

    expect(await financialEvidence(database, walletId, transactionId))
      .toEqual(beforeUpgrade);
    expect(await tableCount(database, 'paper_position_monitoring_tasks')).toBe(0);
    expect(await tableCount(database, 'paper_market_shadow_observations')).toBe(0);

    const schemaObjects = await sql<{
      readonly authority_columns: number;
      readonly authority_constraints: number;
      readonly authority_indexes: number;
      readonly shadow_constraints: number;
      readonly shadow_indexes: number;
      readonly shadow_triggers: number;
    }>`
      select
        (
          select count(*)::int
          from information_schema.columns
          where table_schema = 'public'
            and table_name = 'paper_position_monitoring_tasks'
            and column_name in (
              'observation_authority',
              'next_financial_observation_sequence'
            )
        ) as authority_columns,
        (
          select count(*)::int
          from pg_constraint
          where conname in (
              'paper_position_monitoring_tasks_authority_check',
              'paper_position_monitoring_tasks_financial_sequence_check'
            )
            and conrelid = to_regclass('public.paper_position_monitoring_tasks')
        ) as authority_constraints,
        (
          select count(*)::int
          from pg_indexes
          where schemaname = 'public'
            and indexname = 'paper_position_monitoring_tasks_financial_due_index'
        ) as authority_indexes,
        (
          select count(*)::int
          from pg_constraint
          where conname = 'paper_market_shadow_observations_position_attempt_unique'
            and conrelid = to_regclass('public.paper_market_shadow_observations')
        ) as shadow_constraints,
        (
          select count(*)::int
          from pg_indexes
          where schemaname = 'public'
            and indexname in (
              'paper_market_shadow_observations_position_created_index',
              'paper_market_shadow_observations_request_index',
              'paper_market_shadow_observations_outcome_created_index'
            )
        ) as shadow_indexes,
        (
          select count(*)::int
          from pg_trigger
          where tgname = 'paper_market_shadow_observations_append_only'
            and tgrelid = to_regclass('public.paper_market_shadow_observations')
            and not tgisinternal
        ) as shadow_triggers
    `.execute(database);

    expect(schemaObjects.rows[0]).toEqual({
      authority_columns: 2,
      authority_constraints: 2,
      authority_indexes: 1,
      shadow_constraints: 1,
      shadow_indexes: 3,
      shadow_triggers: 1,
    });
  });

  it('fails compatibility when migration history exists but a required schema object is absent', async () => {
    await migrateTestDatabaseTo(database, migration009);
    await sql`
      drop index paper_position_monitoring_tasks_financial_due_index
    `.execute(database);

    const compatibility = await inspectDatabaseSchemaCompatibility(
      database,
      monitoringEnabled,
    );

    expect(compatibility).toMatchObject({
      compatible: false,
      detectedVersion: 9,
      requiredVersion: 9,
      missingCapabilities: ['PAPER_MARKET_AUTHORITY_SCHEMA_UNAVAILABLE'],
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
