import { Kysely, PostgresDialect, sql, type ColumnType, type Generated } from 'kysely';
import { Pool, types, type PoolConfig } from 'pg';

import type { EngineConfig } from '../../config/env.js';
import type { EventEnvelope } from '../../contracts/events/event-envelope.schema.js';
import type { EvaluationOutcome } from '../../domain/opportunities/evaluation-policy.js';
import { canonicalizeDatabaseDecimal } from '../../shared/amount/canonical-decimal.js';

types.setTypeParser(types.builtins.NUMERIC, canonicalizeDatabaseDecimal);

export type JsonValue =
  | null
  | boolean
  | number
  | string
  | readonly JsonValue[]
  | { readonly [key: string]: JsonValue };

export interface CommandInboxTable {
  id: string;
  idempotency_key: string;
  auth_jti: string;
  subject: string;
  command_name: string;
  request_hash: string;
  status: 'processing' | 'succeeded';
  response: JsonValue | null;
  correlation_id: string;
  traceparent: string;
  created_at: ColumnType<Date, Date | string | undefined, never>;
  completed_at: ColumnType<Date | null, Date | string | null | undefined, Date | string | null>;
}

export interface EventOutboxTable {
  id: string;
  event_type: string;
  aggregate_type: string;
  aggregate_id: string;
  aggregate_version: number;
  envelope: EventEnvelope;
  payload_sha256: string;
  correlation_id: string;
  traceparent: string;
  status: 'pending' | 'processing' | 'published';
  available_at: ColumnType<Date, Date | string | undefined, Date | string>;
  claimed_by: string | null;
  claimed_at: ColumnType<Date | null, Date | string | null | undefined, Date | string | null>;
  attempt_count: Generated<number>;
  published_at: ColumnType<Date | null, Date | string | null | undefined, Date | string | null>;
  created_at: ColumnType<Date, Date | string | undefined, never>;
}

export interface EventDeliveryAttemptTable {
  id: Generated<number>;
  event_id: string;
  attempt_number: number;
  outcome: 'published' | 'failed';
  response_status: number | null;
  error_code: string | null;
  next_attempt_at: ColumnType<Date | null, Date | string | null | undefined, never>;
  created_at: ColumnType<Date, Date | string | undefined, never>;
}

export interface OpportunityTable {
  id: string;
  aggregate_version: 1;
  state: 'recorded';
  source_system: string;
  source_opportunity_id: string;
  control_plane_user_id: string;
  discovery_key: string;
  scanner: 'new-token' | 'momentum';
  network_id: string;
  asset_address: string;
  symbol: string | null;
  name: string | null;
  price_usd: string | null;
  price_provider: string | null;
  market_cap_usd: string | null;
  market_cap_provider: string | null;
  liquidity_usd: string | null;
  liquidity_provider: string | null;
  volume_usd: string | null;
  volume_provider: string | null;
  volume_window: '1m' | '5m' | null;
  pair_address: string | null;
  dex: string | null;
  pair_provider: string | null;
  qualified_at: Date;
  discovery_market_cap_usd: string | null;
  move_since_discovery_percent: string | null;
  classification: string | null;
  security: JsonValue | null;
  accepted_request: JsonValue;
  request_sha256: string;
  received_at: ColumnType<Date, Date | string | undefined, never>;
  created_at: ColumnType<Date, Date | string | undefined, never>;
}

export interface EvaluationPolicyTable {
  policy_key: string;
  policy_version: number;
  algorithm_key: string;
  algorithm_version: number;
  definition: JsonValue;
  definition_sha256: string;
  created_at: ColumnType<Date, Date | string | undefined, never>;
}

export interface OpportunityEvaluationTaskTable {
  opportunity_id: string;
  policy_key: string;
  policy_version: number;
  source_event_id: string;
  status: 'pending' | 'processing' | 'completed';
  available_at: ColumnType<Date, Date | string | undefined, Date | string>;
  claimed_by: string | null;
  claimed_at: ColumnType<Date | null, Date | string | null | undefined, Date | string | null>;
  attempt_count: Generated<number>;
  last_error_code: string | null;
  completed_at: ColumnType<Date | null, Date | string | null | undefined, Date | string | null>;
  created_at: ColumnType<Date, Date | string | undefined, never>;
}

export interface OpportunityEvaluationTable {
  id: string;
  opportunity_id: string;
  policy_key: string;
  policy_version: number;
  policy_snapshot: JsonValue;
  policy_definition_sha256: string;
  source_request_sha256: string;
  evaluation_input_sha256: string;
  outcome: EvaluationOutcome;
  reason_codes: JsonValue;
  advisory_codes: JsonValue;
  evidence: JsonValue;
  result_sha256: string;
  correlation_id: string;
  traceparent: string;
  created_at: ColumnType<Date, Date | string | undefined, never>;
}

export interface PaperPositionLifecycleTable {
  id: string;
  source_position_id: string;
  source_opportunity_id: string;
  opportunity_id: string;
  control_plane_user_id: string;
  network_id: string;
  asset_address: string;
  symbol: string | null;
  initial_investment_native: string;
  entry_market_cap_usd: string;
  entry_price_usd: string | null;
  entry_liquidity_usd: string | null;
  strategy_snapshot: JsonValue;
  strategy_sha256: string;
  policy_key: string;
  policy_version: number;
  lifecycle_version: number;
  last_observation_id: string | null;
  last_observation_sequence: number;
  last_market_cap_usd: string | null;
  last_price_usd: string | null;
  last_liquidity_usd: string | null;
  peak_market_cap_usd: string;
  peak_multiple: string;
  max_drawdown_percent: string;
  protection_state: 'none' | 'level_1' | 'level_2';
  state: 'open' | 'terminal';
  registered_at: ColumnType<Date, Date | string, never>;
  last_observed_at: ColumnType<Date | null, Date | string | null | undefined, Date | string | null>;
  terminal_at: ColumnType<Date | null, Date | string | null | undefined, Date | string | null>;
  created_at: ColumnType<Date, Date | string | undefined, never>;
  updated_at: ColumnType<Date, Date | string | undefined, Date | string>;
}

export interface PaperPositionLifecycleDecisionTable {
  id: string;
  position_id: string;
  observation_id: string;
  observation_sequence: number;
  lifecycle_version: number;
  request_sha256: string;
  result_sha256: string;
  evaluated_event_id: string;
  exit_event_id: string | null;
  decision: 'HOLD' | 'EXIT';
  exit_type: 'stop_loss' | 'protected_floor_exit' | null;
  observed_market_cap_usd: string;
  observed_price_usd: string | null;
  observed_liquidity_usd: string | null;
  observed_multiple: string;
  trigger_multiple: string | null;
  peak_market_cap_usd: string;
  peak_multiple: string;
  drawdown_percent: string;
  protection_before: 'none' | 'level_1' | 'level_2';
  protection_after: 'none' | 'level_1' | 'level_2';
  transitions: JsonValue;
  evidence: JsonValue;
  correlation_id: string;
  traceparent: string;
  observed_at: ColumnType<Date, Date | string, never>;
  created_at: ColumnType<Date, Date | string | undefined, never>;
}

export interface Database {
  command_inbox: CommandInboxTable;
  event_outbox: EventOutboxTable;
  event_delivery_attempts: EventDeliveryAttemptTable;
  opportunities: OpportunityTable;
  evaluation_policies: EvaluationPolicyTable;
  opportunity_evaluation_tasks: OpportunityEvaluationTaskTable;
  opportunity_evaluations: OpportunityEvaluationTable;
  paper_position_lifecycles: PaperPositionLifecycleTable;
  paper_position_lifecycle_decisions: PaperPositionLifecycleDecisionTable;
}

export function createDatabasePoolConfig(config: EngineConfig): PoolConfig {
  const ssl = config.databaseSsl
    ? {
        rejectUnauthorized: true,
        ...(config.databaseCaCertificate === undefined
          ? {}
          : { ca: config.databaseCaCertificate }),
      }
    : undefined;

  return {
    connectionString: config.databaseUrl,
    max: config.databaseMaxConnections,
    ssl,
    application_name: config.serviceName,
  };
}

export function createDatabase(config: EngineConfig): Kysely<Database> {
  return new Kysely<Database>({
    dialect: new PostgresDialect({
      pool: new Pool(createDatabasePoolConfig(config)),
    }),
  });
}

export async function pingDatabase(database: Kysely<Database>): Promise<void> {
  await sql<{ readonly healthy: number }>`select 1 as healthy`.execute(database);
}
