import { sql, type Kysely } from 'kysely';

export async function up(database: Kysely<unknown>): Promise<void> {
  await database.schema
    .createTable('opportunities')
    .addColumn('id', 'varchar(26)', (column) => column.primaryKey())
    .addColumn('aggregate_version', 'integer', (column) => column.notNull().defaultTo(1))
    .addColumn('state', 'varchar(16)', (column) => column.notNull().defaultTo('recorded'))
    .addColumn('source_system', 'varchar(64)', (column) => column.notNull())
    .addColumn('source_opportunity_id', 'varchar(64)', (column) => column.notNull())
    .addColumn('control_plane_user_id', 'varchar(64)', (column) => column.notNull())
    .addColumn('discovery_key', 'varchar(64)', (column) => column.notNull())
    .addColumn('scanner', 'varchar(32)', (column) => column.notNull())
    .addColumn('network_id', 'varchar(64)', (column) => column.notNull())
    .addColumn('asset_address', 'varchar(128)', (column) => column.notNull())
    .addColumn('symbol', 'varchar(64)')
    .addColumn('name', 'varchar(255)')
    .addColumn('price_usd', sql`numeric(78, 30)`)
    .addColumn('price_provider', 'varchar(32)')
    .addColumn('market_cap_usd', sql`numeric(78, 30)`)
    .addColumn('market_cap_provider', 'varchar(32)')
    .addColumn('liquidity_usd', sql`numeric(78, 30)`)
    .addColumn('liquidity_provider', 'varchar(32)')
    .addColumn('volume_usd', sql`numeric(78, 30)`)
    .addColumn('volume_provider', 'varchar(32)')
    .addColumn('volume_window', 'varchar(2)')
    .addColumn('pair_address', 'varchar(128)')
    .addColumn('dex', 'varchar(128)')
    .addColumn('pair_provider', 'varchar(32)')
    .addColumn('qualified_at', 'timestamptz', (column) => column.notNull())
    .addColumn('discovery_market_cap_usd', sql`numeric(78, 30)`)
    .addColumn('move_since_discovery_percent', sql`numeric(78, 30)`)
    .addColumn('classification', 'varchar(64)')
    .addColumn('security', 'jsonb')
    .addColumn('accepted_request', 'jsonb', (column) => column.notNull())
    .addColumn('request_sha256', 'char(64)', (column) => column.notNull())
    .addColumn('received_at', 'timestamptz', (column) =>
      column.notNull().defaultTo(sql`CURRENT_TIMESTAMP`),
    )
    .addColumn('created_at', 'timestamptz', (column) =>
      column.notNull().defaultTo(sql`CURRENT_TIMESTAMP`),
    )
    .addCheckConstraint('opportunities_aggregate_version_check', sql`aggregate_version = 1`)
    .addCheckConstraint('opportunities_state_check', sql`state = 'recorded'`)
    .addCheckConstraint('opportunities_scanner_check', sql`scanner in ('new-token', 'momentum')`)
    .addCheckConstraint(
      'opportunities_network_check',
      sql`network_id in ('solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp', 'eip155:1')`,
    )
    .addCheckConstraint(
      'opportunities_volume_window_check',
      sql`volume_window is null or volume_window in ('1m', '5m')`,
    )
    .addCheckConstraint(
      'opportunities_market_provider_check',
      sql`(price_provider is null or price_provider in ('birdeye', 'dexscreener'))
        and (market_cap_provider is null or market_cap_provider in ('birdeye', 'dexscreener'))
        and (liquidity_provider is null or liquidity_provider in ('birdeye', 'dexscreener'))
        and (volume_provider is null or volume_provider in ('birdeye', 'dexscreener'))
        and (pair_provider is null or pair_provider = 'dexscreener')`,
    )
    .addCheckConstraint(
      'opportunities_measurement_shape_check',
      sql`(price_usd is null) = (price_provider is null)
        and (market_cap_usd is null) = (market_cap_provider is null)
        and (liquidity_usd is null) = (liquidity_provider is null)
        and ((volume_usd is null and volume_provider is null and volume_window is null)
          or (volume_usd is not null and volume_provider is not null and volume_window is not null))
        and ((pair_address is null and pair_provider is null and dex is null)
          or (pair_address is not null and pair_provider is not null))`,
    )
    .addCheckConstraint(
      'opportunities_non_negative_measurements_check',
      sql`(price_usd is null or price_usd >= 0)
        and (market_cap_usd is null or market_cap_usd >= 0)
        and (liquidity_usd is null or liquidity_usd >= 0)
        and (volume_usd is null or volume_usd >= 0)
        and (discovery_market_cap_usd is null or discovery_market_cap_usd >= 0)`,
    )
    .addUniqueConstraint('opportunities_source_unique', [
      'source_system',
      'source_opportunity_id',
    ])
    .addUniqueConstraint('opportunities_user_discovery_unique', [
      'control_plane_user_id',
      'discovery_key',
    ])
    .execute();
}

export async function down(database: Kysely<unknown>): Promise<void> {
  await database.schema.dropTable('opportunities').ifExists().execute();
}
