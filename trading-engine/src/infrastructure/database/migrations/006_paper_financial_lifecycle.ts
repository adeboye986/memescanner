import { sql, type Kysely } from 'kysely';

export async function up(database: Kysely<unknown>): Promise<void> {
  await database.schema
    .createTable('paper_exit_orders')
    .addColumn('id', 'varchar(26)', (column) => column.primaryKey())
    .addColumn('position_id', 'varchar(26)', (column) =>
      column.notNull().references('paper_positions.id').onDelete('restrict').unique(),
    )
    .addColumn('wallet_id', 'varchar(26)', (column) =>
      column.notNull().references('paper_wallets.id').onDelete('restrict'),
    )
    .addColumn('side', 'varchar(8)', (column) => column.notNull())
    .addColumn('order_type', 'varchar(32)', (column) => column.notNull())
    .addColumn('status', 'varchar(16)', (column) => column.notNull())
    .addColumn('asset_address', 'varchar(128)', (column) => column.notNull())
    .addColumn('quantity', 'numeric(78, 30)', (column) => column.notNull())
    .addColumn('currency', 'varchar(16)', (column) => column.notNull())
    .addColumn('market_snapshot', 'jsonb', (column) => column.notNull())
    .addColumn('created_at', 'timestamptz', (column) => column.notNull().defaultTo(sql`CURRENT_TIMESTAMP`))
    .addCheckConstraint('paper_exit_orders_shape_check', sql`
      side = 'sell' and order_type = 'simulated_market' and status = 'filled' and currency = 'SOL'
    `)
    .addCheckConstraint('paper_exit_orders_quantity_check', sql`quantity > 0`)
    .execute();

  await database.schema
    .createTable('paper_exit_fills')
    .addColumn('id', 'varchar(26)', (column) => column.primaryKey())
    .addColumn('order_id', 'varchar(26)', (column) =>
      column.notNull().references('paper_exit_orders.id').onDelete('restrict').unique(),
    )
    .addColumn('cost_basis_native', 'numeric(78, 30)', (column) => column.notNull())
    .addColumn('proceeds_native', 'numeric(78, 30)', (column) => column.notNull())
    .addColumn('realized_pnl_native', 'numeric(78, 30)', (column) => column.notNull())
    .addColumn('exit_price_usd', 'numeric(78, 30)', (column) => column.notNull())
    .addColumn('exit_market_cap_usd', 'numeric(78, 30)', (column) => column.notNull())
    .addColumn('observed_multiple', 'numeric(78, 30)', (column) => column.notNull())
    .addColumn('quantity', 'numeric(78, 30)', (column) => column.notNull())
    .addColumn('quantity_unit', 'varchar(64)', (column) => column.notNull())
    .addColumn('fill_model', 'varchar(64)', (column) => column.notNull())
    .addColumn('executed_at', 'timestamptz', (column) => column.notNull())
    .addColumn('created_at', 'timestamptz', (column) => column.notNull().defaultTo(sql`CURRENT_TIMESTAMP`))
    .addCheckConstraint('paper_exit_fills_values_check', sql`
      cost_basis_native > 0 and proceeds_native > 0 and exit_price_usd > 0
      and exit_market_cap_usd > 0 and observed_multiple > 0 and quantity > 0
      and proceeds_native - cost_basis_native = realized_pnl_native
    `)
    .addCheckConstraint('paper_exit_fills_model_check', sql`
      quantity_unit = 'normalized_position_unit'
      and fill_model = 'observed_market_cap_ratio_v1'
    `)
    .execute();

  await database.schema
    .createTable('paper_exit_settlements')
    .addColumn('id', 'varchar(26)', (column) => column.primaryKey())
    .addColumn('position_id', 'varchar(26)', (column) =>
      column.notNull().references('paper_positions.id').onDelete('restrict').unique(),
    )
    .addColumn('decision_id', 'varchar(26)', (column) =>
      column.notNull().references('paper_position_lifecycle_decisions.id').onDelete('restrict').unique(),
    )
    .addColumn('order_id', 'varchar(26)', (column) =>
      column.notNull().references('paper_exit_orders.id').onDelete('restrict').unique(),
    )
    .addColumn('fill_id', 'varchar(26)', (column) =>
      column.notNull().references('paper_exit_fills.id').onDelete('restrict').unique(),
    )
    .addColumn('ledger_transaction_id', 'varchar(26)', (column) =>
      column.notNull().references('paper_ledger_transactions.id').onDelete('restrict').unique(),
    )
    .addColumn('event_id', 'varchar(26)', (column) => column.notNull().unique())
    .addColumn('cost_basis_native', 'numeric(78, 30)', (column) => column.notNull())
    .addColumn('proceeds_native', 'numeric(78, 30)', (column) => column.notNull())
    .addColumn('realized_pnl_native', 'numeric(78, 30)', (column) => column.notNull())
    .addColumn('result_sha256', 'char(64)', (column) => column.notNull())
    .addColumn('correlation_id', 'varchar(128)', (column) => column.notNull())
    .addColumn('traceparent', 'varchar(55)', (column) => column.notNull())
    .addColumn('settled_at', 'timestamptz', (column) => column.notNull())
    .addColumn('created_at', 'timestamptz', (column) => column.notNull().defaultTo(sql`CURRENT_TIMESTAMP`))
    .addCheckConstraint('paper_exit_settlements_values_check', sql`
      cost_basis_native > 0 and proceeds_native > 0
      and proceeds_native - cost_basis_native = realized_pnl_native
    `)
    .execute();

  await database.schema
    .alterTable('paper_positions')
    .addColumn('exit_price_usd', 'numeric(78, 30)')
    .addColumn('exit_market_cap_usd', 'numeric(78, 30)')
    .addColumn('exit_proceeds_native', 'numeric(78, 30)')
    .addColumn('realized_pnl_native', 'numeric(78, 30)')
    .execute();

  for (const table of ['paper_exit_orders', 'paper_exit_fills', 'paper_exit_settlements']) {
    await sql.raw(`
      create trigger ${table}_append_only
      before update or delete on ${table}
      for each row execute function reject_paper_financial_evidence_mutation()
    `).execute(database);
  }
}

export async function down(database: Kysely<unknown>): Promise<void> {
  await database.schema
    .alterTable('paper_positions')
    .dropColumn('realized_pnl_native')
    .dropColumn('exit_proceeds_native')
    .dropColumn('exit_market_cap_usd')
    .dropColumn('exit_price_usd')
    .execute();
  await database.schema.dropTable('paper_exit_settlements').ifExists().execute();
  await database.schema.dropTable('paper_exit_fills').ifExists().execute();
  await database.schema.dropTable('paper_exit_orders').ifExists().execute();
}
