import { sql, type Kysely } from 'kysely';

export async function up(database: Kysely<unknown>): Promise<void> {
  await database.schema
    .createTable('paper_position_monitoring_tasks')
    .addColumn('position_id', 'varchar(26)', (column) =>
      column.primaryKey().references('paper_positions.id').onDelete('restrict'),
    )
    .addColumn('network_id', 'varchar(64)', (column) => column.notNull())
    .addColumn('asset_address', 'varchar(128)', (column) => column.notNull())
    .addColumn('monitoring_state', 'varchar(16)', (column) =>
      column.notNull().defaultTo('pending'),
    )
    .addColumn('next_observation_due_at', 'timestamptz', (column) => column.notNull())
    .addColumn('lease_owner', 'varchar(26)')
    .addColumn('lease_expires_at', 'timestamptz')
    .addColumn('last_successful_observation_at', 'timestamptz')
    .addColumn('last_fetch_attempted_at', 'timestamptz')
    .addColumn('consecutive_failure_count', 'integer', (column) =>
      column.notNull().defaultTo(0),
    )
    .addColumn('provider_backoff_until', 'timestamptz')
    .addColumn('last_error_code', 'varchar(128)')
    .addColumn('last_http_status', 'integer')
    .addColumn('last_provider', 'varchar(64)')
    .addColumn('last_market_cap_usd', 'numeric(78, 30)')
    .addColumn('last_price_usd', 'numeric(78, 30)')
    .addColumn('last_liquidity_usd', 'numeric(78, 30)')
    .addColumn('last_fetched_at', 'timestamptz')
    .addColumn('last_provider_observed_at', 'timestamptz')
    .addColumn('created_at', 'timestamptz', (column) =>
      column.notNull().defaultTo(sql`CURRENT_TIMESTAMP`),
    )
    .addColumn('updated_at', 'timestamptz', (column) =>
      column.notNull().defaultTo(sql`CURRENT_TIMESTAMP`),
    )
    .addCheckConstraint('paper_position_monitoring_tasks_network_check', sql`
      network_id = 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp'
    `)
    .addCheckConstraint('paper_position_monitoring_tasks_state_check', sql`
      monitoring_state in ('pending', 'processing', 'completed')
    `)
    .addCheckConstraint('paper_position_monitoring_tasks_lease_check', sql`
      (monitoring_state = 'processing' and lease_owner is not null and lease_expires_at is not null)
      or (monitoring_state <> 'processing' and lease_owner is null and lease_expires_at is null)
    `)
    .addCheckConstraint('paper_position_monitoring_tasks_failures_check', sql`
      consecutive_failure_count >= 0
    `)
    .addCheckConstraint('paper_position_monitoring_tasks_error_code_check', sql`
      last_error_code is null or last_error_code ~ '^[A-Z][A-Z0-9_]{0,127}$'
    `)
    .addCheckConstraint('paper_position_monitoring_tasks_http_status_check', sql`
      last_http_status is null or last_http_status between 100 and 599
    `)
    .addCheckConstraint('paper_position_monitoring_tasks_market_check', sql`
      (last_market_cap_usd is null or last_market_cap_usd > 0)
      and (last_price_usd is null or last_price_usd > 0)
      and (last_liquidity_usd is null or last_liquidity_usd >= 0)
    `)
    .execute();

  await sql`
    create index paper_position_monitoring_tasks_due_index
      on paper_position_monitoring_tasks (next_observation_due_at, created_at)
      where monitoring_state = 'pending'
  `.execute(database);

  await sql`
    create index paper_position_monitoring_tasks_expired_lease_index
      on paper_position_monitoring_tasks (lease_expires_at)
      where monitoring_state = 'processing'
  `.execute(database);
}

export async function down(database: Kysely<unknown>): Promise<void> {
  await database.schema.dropTable('paper_position_monitoring_tasks').execute();
}
