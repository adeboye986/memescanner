import { Kysely, PostgresDialect, sql, type ColumnType, type Generated } from 'kysely';
import { Pool } from 'pg';

import type { EngineConfig } from '../../config/env.js';
import type { EventEnvelope } from '../../contracts/events/event-envelope.schema.js';

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

export interface Database {
  command_inbox: CommandInboxTable;
  event_outbox: EventOutboxTable;
  event_delivery_attempts: EventDeliveryAttemptTable;
}

export function createDatabase(config: EngineConfig): Kysely<Database> {
  return new Kysely<Database>({
    dialect: new PostgresDialect({
      pool: new Pool({
        connectionString: config.databaseUrl,
        max: config.databaseMaxConnections,
        ssl: config.databaseSsl ? { rejectUnauthorized: true } : undefined,
        application_name: config.serviceName,
      }),
    }),
  });
}

export async function pingDatabase(database: Kysely<Database>): Promise<void> {
  await sql<{ readonly healthy: number }>`select 1 as healthy`.execute(database);
}
