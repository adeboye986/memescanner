import { sql, type Kysely } from 'kysely';

export async function up(database: Kysely<unknown>): Promise<void> {
  await database.schema
    .createTable('token_scan_executions')
    .addColumn('id', 'varchar(26)', (column) => column.primaryKey())
    .addColumn('source_system', 'varchar(64)', (column) => column.notNull())
    .addColumn('source_activity_id', 'varchar(64)', (column) => column.notNull())
    .addColumn('control_plane_user_id', 'varchar(64)', (column) => column.notNull())
    .addColumn('scanner', 'varchar(32)', (column) => column.notNull())
    .addColumn('network_id', 'varchar(64)', (column) => column.notNull())
    .addColumn('state', 'varchar(24)', (column) => column.notNull().defaultTo('pending'))
    .addColumn('attempt_count', 'integer', (column) => column.notNull().defaultTo(0))
    .addColumn('next_attempt_at', 'timestamptz', (column) => column.notNull().defaultTo(sql`CURRENT_TIMESTAMP`))
    .addColumn('lease_owner', 'varchar(64)')
    .addColumn('lease_expires_at', 'timestamptz')
    .addColumn('request', 'jsonb', (column) => column.notNull())
    .addColumn('request_sha256', 'char(64)', (column) => column.notNull())
    .addColumn('idempotency_key', 'varchar(128)', (column) => column.notNull().unique())
    .addColumn('correlation_id', 'varchar(128)', (column) => column.notNull())
    .addColumn('traceparent', 'varchar(55)', (column) => column.notNull())
    .addColumn('discovered_count', 'integer', (column) => column.notNull().defaultTo(0))
    .addColumn('examined_count', 'integer', (column) => column.notNull().defaultTo(0))
    .addColumn('qualified_count', 'integer', (column) => column.notNull().defaultTo(0))
    .addColumn('opportunities_created_count', 'integer', (column) => column.notNull().defaultTo(0))
    .addColumn('opportunities_existing_count', 'integer', (column) => column.notNull().defaultTo(0))
    .addColumn('skipped_count', 'integer', (column) => column.notNull().defaultTo(0))
    .addColumn('failed_count', 'integer', (column) => column.notNull().defaultTo(0))
    .addColumn('provider_failures', 'jsonb', (column) => column.notNull().defaultTo(sql`'[]'::jsonb`))
    .addColumn('last_error_code', 'varchar(128)')
    .addColumn('started_at', 'timestamptz')
    .addColumn('completed_at', 'timestamptz')
    .addColumn('created_at', 'timestamptz', (column) => column.notNull().defaultTo(sql`CURRENT_TIMESTAMP`))
    .addColumn('updated_at', 'timestamptz', (column) => column.notNull().defaultTo(sql`CURRENT_TIMESTAMP`))
    .addUniqueConstraint('token_scan_executions_source_unique', [
      'source_system',
      'source_activity_id',
    ])
    .addCheckConstraint(
      'token_scan_executions_scanner_check',
      sql`scanner in ('new-token', 'momentum')`,
    )
    .addCheckConstraint(
      'token_scan_executions_network_check',
      sql`network_id in ('solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp', 'eip155:1')`,
    )
    .addCheckConstraint(
      'token_scan_executions_state_check',
      sql`state in ('pending', 'processing', 'succeeded', 'succeeded_with_warnings', 'failed')`,
    )
    .addCheckConstraint(
      'token_scan_executions_lease_check',
      sql`(state = 'processing' and lease_owner is not null and lease_expires_at is not null)
        or (state <> 'processing' and lease_owner is null and lease_expires_at is null)`,
    )
    .addCheckConstraint(
      'token_scan_executions_count_check',
      sql`attempt_count >= 0 and discovered_count >= 0 and examined_count >= 0
        and qualified_count >= 0 and opportunities_created_count >= 0
        and opportunities_existing_count >= 0 and skipped_count >= 0 and failed_count >= 0
        and examined_count <= discovered_count and qualified_count <= examined_count
        and opportunities_created_count + opportunities_existing_count <= qualified_count
        and skipped_count <= examined_count and failed_count <= examined_count`,
    )
    .execute();

  await database.schema
    .createIndex('token_scan_executions_claim_index')
    .on('token_scan_executions')
    .columns(['state', 'next_attempt_at', 'created_at'])
    .execute();
  await database.schema
    .createIndex('token_scan_executions_expired_lease_index')
    .on('token_scan_executions')
    .columns(['state', 'lease_expires_at'])
    .execute();

  await database.schema
    .createTable('token_discoveries')
    .addColumn('id', 'varchar(26)', (column) => column.primaryKey())
    .addColumn('scan_execution_id', 'varchar(26)', (column) =>
      column.notNull().references('token_scan_executions.id').onDelete('restrict'),
    )
    .addColumn('network_id', 'varchar(64)', (column) => column.notNull())
    .addColumn('asset_address', 'varchar(128)', (column) => column.notNull())
    .addColumn('symbol', 'varchar(64)')
    .addColumn('name', 'varchar(255)')
    .addColumn('provider', 'varchar(32)', (column) => column.notNull())
    .addColumn('discovery_index', 'integer', (column) => column.notNull())
    .addColumn('state', 'varchar(24)', (column) => column.notNull())
    .addColumn('reason_code', 'varchar(128)')
    .addColumn('market_snapshot', 'jsonb')
    .addColumn('security_snapshot', 'jsonb')
    .addColumn('qualification_snapshot', 'jsonb')
    .addColumn('opportunity_id', 'varchar(26)', (column) =>
      column.references('opportunities.id').onDelete('restrict'),
    )
    .addColumn('created_at', 'timestamptz', (column) => column.notNull().defaultTo(sql`CURRENT_TIMESTAMP`))
    .addColumn('updated_at', 'timestamptz', (column) => column.notNull().defaultTo(sql`CURRENT_TIMESTAMP`))
    .addUniqueConstraint('token_discoveries_scan_asset_unique', [
      'scan_execution_id',
      'network_id',
      'asset_address',
    ])
    .addCheckConstraint(
      'token_discoveries_provider_check',
      sql`provider in ('birdeye', 'dexscreener', 'geckoterminal')`,
    )
    .addCheckConstraint(
      'token_discoveries_state_check',
      sql`state in ('discovered', 'skipped', 'qualified', 'qualified_downstream_failed', 'opportunity_created', 'opportunity_existing', 'failed')`,
    )
    .addCheckConstraint('token_discoveries_index_check', sql`discovery_index >= 0`)
    .execute();

  await database.schema
    .createIndex('token_discoveries_scan_state_index')
    .on('token_discoveries')
    .columns(['scan_execution_id', 'state', 'discovery_index'])
    .execute();
  await database.schema
    .createIndex('token_discoveries_asset_index')
    .on('token_discoveries')
    .columns(['network_id', 'asset_address', 'created_at'])
    .execute();
}

export async function down(database: Kysely<unknown>): Promise<void> {
  await database.schema.dropTable('token_discoveries').ifExists().execute();
  await database.schema.dropTable('token_scan_executions').ifExists().execute();
}
