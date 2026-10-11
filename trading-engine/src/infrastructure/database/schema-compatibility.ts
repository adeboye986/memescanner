import { sql, type Kysely } from 'kysely';

import type { EngineConfig } from '../../config/env.js';
import type { Database } from './client.js';

export const PAPER_FINANCIAL_SCHEMA_VERSION = 6;
export const PAPER_ENTRY_SCHEMA_VERSION = 7;
export const PAPER_SHADOW_MONITORING_SCHEMA_VERSION = 8;

const migrationNames = new Map<number, string>([
  [PAPER_FINANCIAL_SCHEMA_VERSION, '006_paper_financial_lifecycle'],
  [PAPER_ENTRY_SCHEMA_VERSION, '007_paper_market_monitoring'],
  [PAPER_SHADOW_MONITORING_SCHEMA_VERSION, '008_paper_market_shadow_observations'],
]);

export type DatabaseSchemaFeatureConfig = Pick<
  EngineConfig,
  | 'paperEntryEnabled'
  | 'paperMarketMonitoringEnabled'
  | 'paperMarketMonitorCanaryUserIds'
>;

export interface DatabaseSchemaSnapshot {
  readonly appliedMigrations: readonly string[];
  readonly paperFinancialSchemaAvailable: boolean;
  readonly paperEntrySchemaAvailable: boolean;
  readonly paperShadowMonitoringSchemaAvailable: boolean;
}

export interface DatabaseSchemaCompatibility {
  readonly compatible: boolean;
  readonly detectedVersion: number;
  readonly requiredVersion: number;
  readonly missingCapabilities: readonly string[];
}

interface DatabaseSchemaCatalogRow {
  readonly migration_table_available: boolean;
  readonly paper_financial_schema_available: boolean;
  readonly paper_entry_schema_available: boolean;
  readonly paper_shadow_monitoring_schema_available: boolean;
}

export class DatabaseSchemaCompatibilityError extends Error {
  public constructor(public readonly compatibility: DatabaseSchemaCompatibility) {
    super(
      `Database schema version ${formatDatabaseSchemaVersion(compatibility.detectedVersion)} `
      + `is incompatible with required version ${formatDatabaseSchemaVersion(compatibility.requiredVersion)} `
      + `(${compatibility.missingCapabilities.join(', ')})`,
    );
    this.name = 'DatabaseSchemaCompatibilityError';
  }
}

export function requiredDatabaseSchemaVersion(
  config: DatabaseSchemaFeatureConfig,
): number {
  if (config.paperMarketMonitoringEnabled) {
    return PAPER_SHADOW_MONITORING_SCHEMA_VERSION;
  }

  if (config.paperEntryEnabled) {
    return PAPER_ENTRY_SCHEMA_VERSION;
  }

  return PAPER_FINANCIAL_SCHEMA_VERSION;
}

export function evaluateDatabaseSchemaCompatibility(
  config: DatabaseSchemaFeatureConfig,
  snapshot: DatabaseSchemaSnapshot,
): DatabaseSchemaCompatibility {
  const requiredVersion = requiredDatabaseSchemaVersion(config);
  const detectedVersion = snapshot.appliedMigrations.reduce(
    (highest, migrationName) => Math.max(highest, migrationVersion(migrationName)),
    0,
  );
  const missingCapabilities: string[] = [];

  if (!migrationApplied(snapshot, PAPER_FINANCIAL_SCHEMA_VERSION)) {
    missingCapabilities.push('MIGRATION_006_NOT_APPLIED');
  }

  if (!snapshot.paperFinancialSchemaAvailable) {
    missingCapabilities.push('PAPER_FINANCIAL_SCHEMA_UNAVAILABLE');
  }

  if (requiredVersion >= PAPER_ENTRY_SCHEMA_VERSION) {
    if (!migrationApplied(snapshot, PAPER_ENTRY_SCHEMA_VERSION)) {
      missingCapabilities.push('MIGRATION_007_NOT_APPLIED');
    }

    if (!snapshot.paperEntrySchemaAvailable) {
      missingCapabilities.push('PAPER_ENTRY_MONITORING_TASK_SCHEMA_UNAVAILABLE');
    }
  }

  if (requiredVersion >= PAPER_SHADOW_MONITORING_SCHEMA_VERSION) {
    if (!migrationApplied(snapshot, PAPER_SHADOW_MONITORING_SCHEMA_VERSION)) {
      missingCapabilities.push('MIGRATION_008_NOT_APPLIED');
    }

    if (!snapshot.paperShadowMonitoringSchemaAvailable) {
      missingCapabilities.push('PAPER_SHADOW_OBSERVATION_SCHEMA_UNAVAILABLE');
    }

    if (config.paperMarketMonitorCanaryUserIds.length === 0) {
      missingCapabilities.push('PAPER_MARKET_MONITOR_CANARY_UNAVAILABLE');
    }
  }

  return {
    compatible: missingCapabilities.length === 0,
    detectedVersion,
    requiredVersion,
    missingCapabilities,
  };
}

export async function inspectDatabaseSchemaCompatibility(
  database: Kysely<Database>,
  config: DatabaseSchemaFeatureConfig,
): Promise<DatabaseSchemaCompatibility> {
  const catalog = await databaseSchemaCatalog(database);
  const migrationResult = catalog.migration_table_available
    ? await sql<{ readonly name: string }>`
        select name from kysely_migration order by name asc
      `.execute(database)
    : undefined;
  const appliedMigrations = migrationResult?.rows.map((row) => row.name) ?? [];

  return evaluateDatabaseSchemaCompatibility(config, {
    appliedMigrations,
    paperFinancialSchemaAvailable: catalog.paper_financial_schema_available,
    paperEntrySchemaAvailable: catalog.paper_entry_schema_available,
    paperShadowMonitoringSchemaAvailable:
      catalog.paper_shadow_monitoring_schema_available,
  });
}

export async function assertDatabaseSchemaCompatible(
  database: Kysely<Database>,
  config: DatabaseSchemaFeatureConfig,
): Promise<DatabaseSchemaCompatibility> {
  const compatibility = await inspectDatabaseSchemaCompatibility(database, config);

  if (!compatibility.compatible) {
    throw new DatabaseSchemaCompatibilityError(compatibility);
  }

  return compatibility;
}

export function formatDatabaseSchemaVersion(version: number): string {
  return String(version).padStart(3, '0');
}

function migrationApplied(
  snapshot: DatabaseSchemaSnapshot,
  version: number,
): boolean {
  const migrationName = migrationNames.get(version);

  return migrationName !== undefined
    && snapshot.appliedMigrations.includes(migrationName);
}

function migrationVersion(migrationName: string): number {
  const match = /^(\d{3})_/.exec(migrationName);

  return match === null ? 0 : Number.parseInt(match[1] ?? '0', 10);
}

async function databaseSchemaCatalog(
  database: Kysely<Database>,
): Promise<DatabaseSchemaCatalogRow> {
  const result = await sql<DatabaseSchemaCatalogRow>`
    select
      to_regclass('public.kysely_migration') is not null
        as migration_table_available,
      (
        to_regclass('public.paper_wallets') is not null
        and to_regclass('public.paper_ledger_transactions') is not null
        and to_regclass('public.paper_ledger_entries') is not null
        and to_regclass('public.paper_entry_intents') is not null
        and to_regclass('public.paper_orders') is not null
        and to_regclass('public.paper_fills') is not null
        and to_regclass('public.paper_positions') is not null
        and to_regclass('public.paper_position_lifecycles') is not null
        and to_regclass('public.paper_position_lifecycle_decisions') is not null
        and to_regclass('public.paper_exit_orders') is not null
        and to_regclass('public.paper_exit_fills') is not null
        and to_regclass('public.paper_exit_settlements') is not null
        and (
          select count(*)::int = 4
          from information_schema.columns
          where table_schema = 'public'
            and table_name = 'paper_positions'
            and column_name in (
              'exit_price_usd',
              'exit_market_cap_usd',
              'exit_proceeds_native',
              'realized_pnl_native'
            )
        )
        and to_regprocedure('public.assert_paper_ledger_transaction_balanced()')
          is not null
        and to_regprocedure('public.reject_paper_financial_evidence_mutation()')
          is not null
      ) as paper_financial_schema_available,
      (
        to_regclass('public.paper_position_monitoring_tasks') is not null
        and (
          select count(*)::int = 21
          from information_schema.columns
          where table_schema = 'public'
            and table_name = 'paper_position_monitoring_tasks'
            and column_name in (
              'position_id',
              'network_id',
              'asset_address',
              'monitoring_state',
              'next_observation_due_at',
              'lease_owner',
              'lease_expires_at',
              'last_successful_observation_at',
              'last_fetch_attempted_at',
              'consecutive_failure_count',
              'provider_backoff_until',
              'last_error_code',
              'last_http_status',
              'last_provider',
              'last_market_cap_usd',
              'last_price_usd',
              'last_liquidity_usd',
              'last_fetched_at',
              'last_provider_observed_at',
              'created_at',
              'updated_at'
            )
        )
        and exists (
          select 1 from pg_indexes
          where schemaname = 'public'
            and indexname = 'paper_position_monitoring_tasks_due_index'
        )
        and exists (
          select 1 from pg_indexes
          where schemaname = 'public'
            and indexname = 'paper_position_monitoring_tasks_expired_lease_index'
        )
      ) as paper_entry_schema_available,
      (
        to_regclass('public.paper_market_shadow_observations') is not null
        and exists (
          select 1 from information_schema.columns
          where table_schema = 'public'
            and table_name = 'paper_position_monitoring_tasks'
            and column_name = 'next_attempt_sequence'
        )
        and (
          select count(*)::int = 25
          from information_schema.columns
          where table_schema = 'public'
            and table_name = 'paper_market_shadow_observations'
            and column_name in (
              'id',
              'position_id',
              'attempt_sequence',
              'request_id',
              'scheduled_due_at',
              'network_id',
              'asset_address',
              'outcome',
              'provider',
              'pair_address',
              'dex',
              'market_cap_usd',
              'price_usd',
              'liquidity_usd',
              'request_started_at',
              'response_received_at',
              'provider_latency_ms',
              'provider_observed_at',
              'fetched_at',
              'error_code',
              'http_status',
              'retry_after_ms',
              'consecutive_failure_count',
              'next_observation_due_at',
              'created_at'
            )
        )
        and exists (
          select 1 from pg_constraint
          where conname = 'paper_market_shadow_observations_position_attempt_unique'
            and conrelid = to_regclass('public.paper_market_shadow_observations')
        )
        and exists (
          select 1 from pg_indexes
          where schemaname = 'public'
            and indexname = 'paper_market_shadow_observations_position_created_index'
        )
        and exists (
          select 1 from pg_indexes
          where schemaname = 'public'
            and indexname = 'paper_market_shadow_observations_request_index'
        )
        and exists (
          select 1 from pg_indexes
          where schemaname = 'public'
            and indexname = 'paper_market_shadow_observations_outcome_created_index'
        )
        and exists (
          select 1 from pg_trigger
          where tgname = 'paper_market_shadow_observations_append_only'
            and tgrelid = to_regclass('public.paper_market_shadow_observations')
            and not tgisinternal
        )
        and to_regprocedure(
          'public.reject_paper_market_shadow_observation_mutation()'
        ) is not null
      ) as paper_shadow_monitoring_schema_available
  `.execute(database);
  const catalog = result.rows[0];

  if (catalog === undefined) {
    throw new Error('Database schema catalog query returned no result');
  }

  return catalog;
}
