import { Kysely, PostgresDialect, sql, type ColumnType, type Generated } from 'kysely';
import { Pool, types, type PoolConfig } from 'pg';

import type { DatabaseConfig } from '../../config/env.js';
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

export interface PaperWalletTable {
  id: string;
  control_plane_user_id: string;
  network_id: string;
  currency: 'SOL';
  opening_balance_native: string;
  opening_reference: string;
  created_at: ColumnType<Date, Date | string | undefined, never>;
}

export interface PaperLedgerTransactionTable {
  id: string;
  wallet_id: string;
  transaction_type: 'opening_balance' | 'entry' | 'exit_settlement';
  reference_id: string;
  idempotency_key: string;
  correlation_id: string;
  occurred_at: ColumnType<Date, Date | string, never>;
  created_at: ColumnType<Date, Date | string | undefined, never>;
}

export interface PaperLedgerEntryTable {
  id: string;
  transaction_id: string;
  wallet_id: string;
  account: 'available' | 'invested' | 'opening_equity' | 'realized_pnl';
  direction: 'debit' | 'credit';
  amount_native: string;
  currency: 'SOL';
  created_at: ColumnType<Date, Date | string | undefined, never>;
}

export interface PaperEntryIntentTable {
  id: string;
  wallet_id: string;
  opportunity_id: string;
  evaluation_id: string;
  source_opportunity_id: string;
  control_plane_user_id: string;
  network_id: string;
  asset_address: string;
  status: 'executed';
  execution_mode: 'paper';
  entry_mode: 'auto';
  notional_native: string;
  intent_snapshot: JsonValue;
  intent_sha256: string;
  authority_snapshot: JsonValue;
  authority_sha256: string;
  idempotency_key: string;
  correlation_id: string;
  traceparent: string;
  accepted_at: ColumnType<Date, Date | string, never>;
  created_at: ColumnType<Date, Date | string | undefined, never>;
}

export interface PaperOrderTable {
  id: string;
  intent_id: string;
  wallet_id: string;
  side: 'buy';
  order_type: 'simulated_market';
  status: 'filled';
  asset_address: string;
  requested_notional_native: string;
  currency: 'SOL';
  market_snapshot: JsonValue;
  created_at: ColumnType<Date, Date | string | undefined, never>;
}

export interface PaperFillTable {
  id: string;
  order_id: string;
  notional_native: string;
  fee_native: string;
  fill_price_usd: string;
  quantity: string;
  quantity_unit: 'normalized_position_unit';
  fill_model: 'observed_mark_normalized_notional_v1';
  executed_at: ColumnType<Date, Date | string, never>;
  created_at: ColumnType<Date, Date | string | undefined, never>;
}

export interface PaperFinancialPositionTable {
  id: string;
  wallet_id: string;
  intent_id: string;
  order_id: string;
  fill_id: string;
  opportunity_id: string;
  evaluation_id: string;
  control_plane_user_id: string;
  network_id: string;
  asset_address: string;
  symbol: string | null;
  state: 'open' | 'closed';
  quantity: string;
  quantity_unit: 'normalized_position_unit';
  cost_basis_native: string;
  entry_price_usd: string;
  entry_market_cap_usd: string;
  entry_liquidity_usd: string | null;
  strategy_snapshot: JsonValue;
  strategy_sha256: string;
  authority_snapshot: JsonValue;
  authority_sha256: string;
  opened_at: ColumnType<Date, Date | string, never>;
  closed_at: ColumnType<Date | null, Date | string | null | undefined, Date | string | null>;
  exit_price_usd: ColumnType<string | null, string | null | undefined, string | null>;
  exit_market_cap_usd: ColumnType<string | null, string | null | undefined, string | null>;
  exit_proceeds_native: ColumnType<string | null, string | null | undefined, string | null>;
  realized_pnl_native: ColumnType<string | null, string | null | undefined, string | null>;
  created_at: ColumnType<Date, Date | string | undefined, never>;
  updated_at: ColumnType<Date, Date | string | undefined, Date | string>;
}

export interface PaperExitOrderTable {
  id: string;
  position_id: string;
  wallet_id: string;
  side: 'sell';
  order_type: 'simulated_market';
  status: 'filled';
  asset_address: string;
  quantity: string;
  currency: 'SOL';
  market_snapshot: JsonValue;
  created_at: ColumnType<Date, Date | string | undefined, never>;
}

export interface PaperExitFillTable {
  id: string;
  order_id: string;
  cost_basis_native: string;
  proceeds_native: string;
  realized_pnl_native: string;
  exit_price_usd: string;
  exit_market_cap_usd: string;
  observed_multiple: string;
  quantity: string;
  quantity_unit: 'normalized_position_unit';
  fill_model: 'observed_market_cap_ratio_v1';
  executed_at: ColumnType<Date, Date | string, never>;
  created_at: ColumnType<Date, Date | string | undefined, never>;
}

export interface PaperExitSettlementTable {
  id: string;
  position_id: string;
  decision_id: string;
  order_id: string;
  fill_id: string;
  ledger_transaction_id: string;
  event_id: string;
  cost_basis_native: string;
  proceeds_native: string;
  realized_pnl_native: string;
  result_sha256: string;
  correlation_id: string;
  traceparent: string;
  settled_at: ColumnType<Date, Date | string, never>;
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
  paper_wallets: PaperWalletTable;
  paper_ledger_transactions: PaperLedgerTransactionTable;
  paper_ledger_entries: PaperLedgerEntryTable;
  paper_entry_intents: PaperEntryIntentTable;
  paper_orders: PaperOrderTable;
  paper_fills: PaperFillTable;
  paper_positions: PaperFinancialPositionTable;
  paper_position_lifecycles: PaperPositionLifecycleTable;
  paper_position_lifecycle_decisions: PaperPositionLifecycleDecisionTable;
  paper_exit_orders: PaperExitOrderTable;
  paper_exit_fills: PaperExitFillTable;
  paper_exit_settlements: PaperExitSettlementTable;
}

export function createDatabasePoolConfig(config: DatabaseConfig): PoolConfig {
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
    application_name: config.serviceName ?? 'meme-scanner-trading-engine-migrate',
  };
}

export function createDatabase(config: DatabaseConfig): Kysely<Database> {
  return new Kysely<Database>({
    dialect: new PostgresDialect({
      pool: new Pool(createDatabasePoolConfig(config)),
    }),
  });
}

export async function pingDatabase(database: Kysely<Database>): Promise<void> {
  await sql<{ readonly healthy: number }>`select 1 as healthy`.execute(database);
}
