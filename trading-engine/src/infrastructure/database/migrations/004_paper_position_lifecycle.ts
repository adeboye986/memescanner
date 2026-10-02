import { sql, type Kysely } from 'kysely';

export async function up(database: Kysely<unknown>): Promise<void> {
  await database.schema
    .createTable('paper_position_lifecycles')
    .addColumn('id', 'varchar(26)', (column) => column.primaryKey())
    .addColumn('source_position_id', 'varchar(32)', (column) => column.notNull().unique())
    .addColumn('source_opportunity_id', 'varchar(32)', (column) => column.notNull().unique())
    .addColumn('opportunity_id', 'varchar(26)', (column) =>
      column.notNull().references('opportunities.id').onDelete('restrict'),
    )
    .addColumn('control_plane_user_id', 'varchar(64)', (column) => column.notNull())
    .addColumn('network_id', 'varchar(64)', (column) => column.notNull())
    .addColumn('asset_address', 'varchar(128)', (column) => column.notNull())
    .addColumn('symbol', 'varchar(32)')
    .addColumn('initial_investment_native', 'numeric(78, 30)', (column) => column.notNull())
    .addColumn('entry_market_cap_usd', 'numeric(78, 30)', (column) => column.notNull())
    .addColumn('entry_price_usd', 'numeric(78, 30)')
    .addColumn('entry_liquidity_usd', 'numeric(78, 30)')
    .addColumn('strategy_snapshot', 'jsonb', (column) => column.notNull())
    .addColumn('strategy_sha256', 'char(64)', (column) => column.notNull())
    .addColumn('policy_key', 'varchar(128)', (column) => column.notNull())
    .addColumn('policy_version', 'integer', (column) => column.notNull())
    .addColumn('lifecycle_version', 'integer', (column) => column.notNull().defaultTo(0))
    .addColumn('last_observation_id', 'varchar(128)')
    .addColumn('last_observation_sequence', 'integer', (column) => column.notNull().defaultTo(0))
    .addColumn('last_market_cap_usd', 'numeric(78, 30)')
    .addColumn('last_price_usd', 'numeric(78, 30)')
    .addColumn('last_liquidity_usd', 'numeric(78, 30)')
    .addColumn('peak_market_cap_usd', 'numeric(78, 30)', (column) => column.notNull())
    .addColumn('peak_multiple', 'numeric(78, 30)', (column) => column.notNull().defaultTo('1'))
    .addColumn('max_drawdown_percent', 'numeric(78, 30)', (column) => column.notNull().defaultTo('0'))
    .addColumn('protection_state', 'varchar(16)', (column) => column.notNull().defaultTo('none'))
    .addColumn('state', 'varchar(16)', (column) => column.notNull().defaultTo('open'))
    .addColumn('registered_at', 'timestamptz', (column) => column.notNull())
    .addColumn('last_observed_at', 'timestamptz')
    .addColumn('terminal_at', 'timestamptz')
    .addColumn('created_at', 'timestamptz', (column) => column.notNull().defaultTo(sql`CURRENT_TIMESTAMP`))
    .addColumn('updated_at', 'timestamptz', (column) => column.notNull().defaultTo(sql`CURRENT_TIMESTAMP`))
    .addCheckConstraint('paper_position_lifecycles_state_check', sql`state in ('open', 'terminal')`)
    .addCheckConstraint('paper_position_lifecycles_protection_check', sql`protection_state in ('none', 'level_1', 'level_2')`)
    .execute();

  await database.schema
    .createIndex('paper_position_lifecycles_user_state_index')
    .on('paper_position_lifecycles')
    .columns(['control_plane_user_id', 'state'])
    .execute();

  await database.schema
    .createTable('paper_position_lifecycle_decisions')
    .addColumn('id', 'varchar(26)', (column) => column.primaryKey())
    .addColumn('position_id', 'varchar(26)', (column) =>
      column.notNull().references('paper_position_lifecycles.id').onDelete('restrict'),
    )
    .addColumn('observation_id', 'varchar(128)', (column) => column.notNull())
    .addColumn('observation_sequence', 'integer', (column) => column.notNull())
    .addColumn('lifecycle_version', 'integer', (column) => column.notNull())
    .addColumn('request_sha256', 'char(64)', (column) => column.notNull())
    .addColumn('result_sha256', 'char(64)', (column) => column.notNull())
    .addColumn('evaluated_event_id', 'varchar(26)', (column) => column.notNull().unique())
    .addColumn('exit_event_id', 'varchar(26)', (column) => column.unique())
    .addColumn('decision', 'varchar(8)', (column) => column.notNull())
    .addColumn('exit_type', 'varchar(32)')
    .addColumn('observed_market_cap_usd', 'numeric(78, 30)', (column) => column.notNull())
    .addColumn('observed_price_usd', 'numeric(78, 30)')
    .addColumn('observed_liquidity_usd', 'numeric(78, 30)')
    .addColumn('observed_multiple', 'numeric(78, 30)', (column) => column.notNull())
    .addColumn('trigger_multiple', 'numeric(78, 30)')
    .addColumn('peak_market_cap_usd', 'numeric(78, 30)', (column) => column.notNull())
    .addColumn('peak_multiple', 'numeric(78, 30)', (column) => column.notNull())
    .addColumn('drawdown_percent', 'numeric(78, 30)', (column) => column.notNull())
    .addColumn('protection_before', 'varchar(16)', (column) => column.notNull())
    .addColumn('protection_after', 'varchar(16)', (column) => column.notNull())
    .addColumn('transitions', 'jsonb', (column) => column.notNull())
    .addColumn('evidence', 'jsonb', (column) => column.notNull())
    .addColumn('correlation_id', 'varchar(128)', (column) => column.notNull())
    .addColumn('traceparent', 'varchar(55)', (column) => column.notNull())
    .addColumn('observed_at', 'timestamptz', (column) => column.notNull())
    .addColumn('created_at', 'timestamptz', (column) => column.notNull().defaultTo(sql`CURRENT_TIMESTAMP`))
    .addUniqueConstraint('paper_lifecycle_decisions_position_observation_unique', ['position_id', 'observation_id'])
    .addUniqueConstraint('paper_lifecycle_decisions_position_sequence_unique', ['position_id', 'observation_sequence'])
    .addCheckConstraint('paper_lifecycle_decisions_decision_check', sql`decision in ('HOLD', 'EXIT')`)
    .execute();

  await sql`
    create function reject_paper_lifecycle_decision_mutation()
    returns trigger
    language plpgsql
    as $$
    begin
      raise exception 'paper lifecycle decisions are append-only';
    end;
    $$
  `.execute(database);

  await sql`
    create trigger paper_lifecycle_decisions_append_only
    before update or delete on paper_position_lifecycle_decisions
    for each row execute function reject_paper_lifecycle_decision_mutation()
  `.execute(database);
}

export async function down(database: Kysely<unknown>): Promise<void> {
  await database.schema.dropTable('paper_position_lifecycle_decisions').ifExists().execute();
  await database.schema.dropTable('paper_position_lifecycles').ifExists().execute();
  await sql`drop function if exists reject_paper_lifecycle_decision_mutation()`.execute(database);
}
