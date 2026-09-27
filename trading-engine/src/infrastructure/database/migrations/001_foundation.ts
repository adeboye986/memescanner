import { sql, type Kysely } from 'kysely';

export async function up(database: Kysely<unknown>): Promise<void> {
  await database.schema
    .createTable('command_inbox')
    .addColumn('id', 'varchar(26)', (column) => column.primaryKey())
    .addColumn('idempotency_key', 'varchar(128)', (column) => column.notNull().unique())
    .addColumn('auth_jti', 'varchar(128)', (column) => column.notNull().unique())
    .addColumn('subject', 'varchar(255)', (column) => column.notNull())
    .addColumn('command_name', 'varchar(128)', (column) => column.notNull())
    .addColumn('request_hash', 'char(64)', (column) => column.notNull())
    .addColumn('status', 'varchar(16)', (column) => column.notNull())
    .addColumn('response', 'jsonb')
    .addColumn('correlation_id', 'varchar(128)', (column) => column.notNull())
    .addColumn('traceparent', 'varchar(55)', (column) => column.notNull())
    .addColumn('created_at', 'timestamptz', (column) =>
      column.notNull().defaultTo(sql`CURRENT_TIMESTAMP`),
    )
    .addColumn('completed_at', 'timestamptz')
    .addCheckConstraint(
      'command_inbox_status_check',
      sql`status in ('processing', 'succeeded')`,
    )
    .execute();

  await database.schema
    .createTable('event_outbox')
    .addColumn('id', 'varchar(26)', (column) => column.primaryKey())
    .addColumn('event_type', 'varchar(160)', (column) => column.notNull())
    .addColumn('aggregate_type', 'varchar(64)', (column) => column.notNull())
    .addColumn('aggregate_id', 'varchar(26)', (column) => column.notNull())
    .addColumn('aggregate_version', 'integer', (column) => column.notNull())
    .addColumn('envelope', 'jsonb', (column) => column.notNull())
    .addColumn('payload_sha256', 'char(64)', (column) => column.notNull())
    .addColumn('correlation_id', 'varchar(128)', (column) => column.notNull())
    .addColumn('traceparent', 'varchar(55)', (column) => column.notNull())
    .addColumn('status', 'varchar(16)', (column) => column.notNull().defaultTo('pending'))
    .addColumn('available_at', 'timestamptz', (column) =>
      column.notNull().defaultTo(sql`CURRENT_TIMESTAMP`),
    )
    .addColumn('claimed_by', 'varchar(64)')
    .addColumn('claimed_at', 'timestamptz')
    .addColumn('attempt_count', 'integer', (column) => column.notNull().defaultTo(0))
    .addColumn('published_at', 'timestamptz')
    .addColumn('created_at', 'timestamptz', (column) =>
      column.notNull().defaultTo(sql`CURRENT_TIMESTAMP`),
    )
    .addCheckConstraint(
      'event_outbox_status_check',
      sql`status in ('pending', 'processing', 'published')`,
    )
    .execute();

  await database.schema
    .createIndex('event_outbox_dispatch_index')
    .on('event_outbox')
    .columns(['status', 'available_at', 'created_at'])
    .execute();

  await database.schema
    .createTable('event_delivery_attempts')
    .addColumn('id', 'bigserial', (column) => column.primaryKey())
    .addColumn('event_id', 'varchar(26)', (column) =>
      column.notNull().references('event_outbox.id').onDelete('cascade'),
    )
    .addColumn('attempt_number', 'integer', (column) => column.notNull())
    .addColumn('outcome', 'varchar(16)', (column) => column.notNull())
    .addColumn('response_status', 'integer')
    .addColumn('error_code', 'varchar(128)')
    .addColumn('next_attempt_at', 'timestamptz')
    .addColumn('created_at', 'timestamptz', (column) =>
      column.notNull().defaultTo(sql`CURRENT_TIMESTAMP`),
    )
    .addCheckConstraint(
      'event_delivery_attempt_outcome_check',
      sql`outcome in ('published', 'failed')`,
    )
    .addUniqueConstraint('event_delivery_attempt_number_unique', [
      'event_id',
      'attempt_number',
    ])
    .execute();
}

export async function down(database: Kysely<unknown>): Promise<void> {
  await database.schema.dropTable('event_delivery_attempts').ifExists().execute();
  await database.schema.dropTable('event_outbox').ifExists().execute();
  await database.schema.dropTable('command_inbox').ifExists().execute();
}
