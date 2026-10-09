import { sql, type Kysely } from 'kysely';

export async function up(database: Kysely<unknown>): Promise<void> {
  await database.schema
    .alterTable('paper_position_monitoring_tasks')
    .addColumn('next_attempt_sequence', 'integer', (column) =>
      column.notNull().defaultTo(1),
    )
    .execute();

  await sql`
    alter table paper_position_monitoring_tasks
      add constraint paper_position_monitoring_tasks_attempt_sequence_check
      check (next_attempt_sequence > 0)
  `.execute(database);

  await database.schema
    .createTable('paper_market_shadow_observations')
    .addColumn('id', 'varchar(26)', (column) => column.primaryKey())
    .addColumn('position_id', 'varchar(26)', (column) =>
      column.notNull().references('paper_positions.id').onDelete('restrict'),
    )
    .addColumn('attempt_sequence', 'integer', (column) => column.notNull())
    .addColumn('request_id', 'varchar(26)', (column) => column.notNull())
    .addColumn('scheduled_due_at', 'timestamptz', (column) => column.notNull())
    .addColumn('network_id', 'varchar(64)', (column) => column.notNull())
    .addColumn('asset_address', 'varchar(128)', (column) => column.notNull())
    .addColumn('outcome', 'varchar(32)', (column) => column.notNull())
    .addColumn('provider', 'varchar(64)', (column) => column.notNull())
    .addColumn('pair_address', 'varchar(128)')
    .addColumn('dex', 'varchar(64)')
    .addColumn('market_cap_usd', 'numeric(78, 30)')
    .addColumn('price_usd', 'numeric(78, 30)')
    .addColumn('liquidity_usd', 'numeric(78, 30)')
    .addColumn('request_started_at', 'timestamptz', (column) => column.notNull())
    .addColumn('response_received_at', 'timestamptz', (column) => column.notNull())
    .addColumn('provider_latency_ms', 'integer', (column) => column.notNull())
    .addColumn('provider_observed_at', 'timestamptz')
    .addColumn('fetched_at', 'timestamptz')
    .addColumn('error_code', 'varchar(128)')
    .addColumn('http_status', 'integer')
    .addColumn('retry_after_ms', 'integer')
    .addColumn('consecutive_failure_count', 'integer', (column) => column.notNull())
    .addColumn('next_observation_due_at', 'timestamptz')
    .addColumn('created_at', 'timestamptz', (column) =>
      column.notNull().defaultTo(sql`CURRENT_TIMESTAMP`),
    )
    .addUniqueConstraint(
      'paper_market_shadow_observations_position_attempt_unique',
      ['position_id', 'attempt_sequence'],
    )
    .addCheckConstraint('paper_market_shadow_observations_attempt_check', sql`
      attempt_sequence > 0 and consecutive_failure_count >= 0
      and provider_latency_ms >= 0 and (retry_after_ms is null or retry_after_ms >= 0)
    `)
    .addCheckConstraint('paper_market_shadow_observations_identity_check', sql`
      network_id = 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp'
      and provider = 'dexscreener'
    `)
    .addCheckConstraint('paper_market_shadow_observations_outcome_check', sql`
      outcome in ('observed', 'unavailable', 'failed', 'discarded_closed')
    `)
    .addCheckConstraint('paper_market_shadow_observations_timing_check', sql`
      response_received_at >= request_started_at
    `)
    .addCheckConstraint('paper_market_shadow_observations_market_check', sql`
      (market_cap_usd is null or market_cap_usd > 0)
      and (price_usd is null or price_usd > 0)
      and (liquidity_usd is null or liquidity_usd >= 0)
    `)
    .addCheckConstraint('paper_market_shadow_observations_error_check', sql`
      (error_code is null or error_code ~ '^[A-Z][A-Z0-9_]{0,127}$')
      and (http_status is null or http_status between 100 and 599)
    `)
    .addCheckConstraint('paper_market_shadow_observations_shape_check', sql`
      (
        outcome = 'observed'
        and market_cap_usd is not null
        and price_usd is not null
        and fetched_at is not null
        and error_code is null
      )
      or (
        outcome in ('unavailable', 'failed')
        and market_cap_usd is null
        and price_usd is null
        and liquidity_usd is null
        and pair_address is null
        and dex is null
        and fetched_at is null
        and provider_observed_at is null
        and error_code is not null
      )
      or outcome = 'discarded_closed'
    `)
    .addCheckConstraint('paper_market_shadow_observations_schedule_check', sql`
      (outcome = 'discarded_closed' and next_observation_due_at is null)
      or (outcome <> 'discarded_closed' and next_observation_due_at is not null)
    `)
    .execute();

  await database.schema
    .createIndex('paper_market_shadow_observations_position_created_index')
    .on('paper_market_shadow_observations')
    .columns(['position_id', 'created_at'])
    .execute();

  await database.schema
    .createIndex('paper_market_shadow_observations_request_index')
    .on('paper_market_shadow_observations')
    .column('request_id')
    .execute();

  await database.schema
    .createIndex('paper_market_shadow_observations_outcome_created_index')
    .on('paper_market_shadow_observations')
    .columns(['outcome', 'created_at'])
    .execute();

  await sql`
    create function reject_paper_market_shadow_observation_mutation()
    returns trigger
    language plpgsql
    as $function$
    begin
      raise exception 'paper market shadow observations are append-only';
    end;
    $function$
  `.execute(database);

  await sql`
    create trigger paper_market_shadow_observations_append_only
    before update or delete on paper_market_shadow_observations
    for each row execute function reject_paper_market_shadow_observation_mutation()
  `.execute(database);
}

export async function down(database: Kysely<unknown>): Promise<void> {
  await database.schema
    .dropTable('paper_market_shadow_observations')
    .ifExists()
    .execute();
  await sql`
    drop function if exists reject_paper_market_shadow_observation_mutation()
  `.execute(database);
  await database.schema
    .alterTable('paper_position_monitoring_tasks')
    .dropColumn('next_attempt_sequence')
    .execute();
}
