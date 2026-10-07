import { sql, type Kysely } from 'kysely';

export async function up(database: Kysely<unknown>): Promise<void> {
  await database.schema
    .createTable('paper_wallets')
    .addColumn('id', 'varchar(26)', (column) => column.primaryKey())
    .addColumn('control_plane_user_id', 'varchar(64)', (column) => column.notNull())
    .addColumn('network_id', 'varchar(64)', (column) => column.notNull())
    .addColumn('currency', 'varchar(16)', (column) => column.notNull())
    .addColumn('opening_balance_native', 'numeric(78, 30)', (column) => column.notNull())
    .addColumn('opening_reference', 'varchar(160)', (column) => column.notNull().unique())
    .addColumn('created_at', 'timestamptz', (column) => column.notNull().defaultTo(sql`CURRENT_TIMESTAMP`))
    .addUniqueConstraint('paper_wallets_user_network_currency_unique', [
      'control_plane_user_id',
      'network_id',
      'currency',
    ])
    .addCheckConstraint('paper_wallets_network_currency_check', sql`
      network_id = 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp' and currency = 'SOL'
    `)
    .addCheckConstraint('paper_wallets_opening_balance_check', sql`opening_balance_native > 0`)
    .execute();

  await database.schema
    .createTable('paper_ledger_transactions')
    .addColumn('id', 'varchar(26)', (column) => column.primaryKey())
    .addColumn('wallet_id', 'varchar(26)', (column) =>
      column.notNull().references('paper_wallets.id').onDelete('restrict'),
    )
    .addColumn('transaction_type', 'varchar(32)', (column) => column.notNull())
    .addColumn('reference_id', 'varchar(160)', (column) => column.notNull().unique())
    .addColumn('idempotency_key', 'varchar(160)', (column) => column.notNull().unique())
    .addColumn('correlation_id', 'varchar(128)', (column) => column.notNull())
    .addColumn('occurred_at', 'timestamptz', (column) => column.notNull())
    .addColumn('created_at', 'timestamptz', (column) => column.notNull().defaultTo(sql`CURRENT_TIMESTAMP`))
    .addCheckConstraint('paper_ledger_transactions_type_check', sql`
      transaction_type in ('opening_balance', 'entry', 'exit_settlement')
    `)
    .execute();

  await database.schema
    .createTable('paper_ledger_entries')
    .addColumn('id', 'varchar(26)', (column) => column.primaryKey())
    .addColumn('transaction_id', 'varchar(26)', (column) =>
      column.notNull().references('paper_ledger_transactions.id').onDelete('restrict'),
    )
    .addColumn('wallet_id', 'varchar(26)', (column) =>
      column.notNull().references('paper_wallets.id').onDelete('restrict'),
    )
    .addColumn('account', 'varchar(32)', (column) => column.notNull())
    .addColumn('direction', 'varchar(8)', (column) => column.notNull())
    .addColumn('amount_native', 'numeric(78, 30)', (column) => column.notNull())
    .addColumn('currency', 'varchar(16)', (column) => column.notNull())
    .addColumn('created_at', 'timestamptz', (column) => column.notNull().defaultTo(sql`CURRENT_TIMESTAMP`))
    .addUniqueConstraint('paper_ledger_entries_transaction_account_unique', [
      'transaction_id',
      'account',
      'direction',
    ])
    .addCheckConstraint('paper_ledger_entries_account_check', sql`
      account in ('available', 'invested', 'opening_equity', 'realized_pnl')
    `)
    .addCheckConstraint('paper_ledger_entries_direction_check', sql`direction in ('debit', 'credit')`)
    .addCheckConstraint('paper_ledger_entries_amount_check', sql`amount_native > 0`)
    .addCheckConstraint('paper_ledger_entries_currency_check', sql`currency = 'SOL'`)
    .execute();

  await database.schema
    .createIndex('paper_ledger_entries_wallet_account_index')
    .on('paper_ledger_entries')
    .columns(['wallet_id', 'account'])
    .execute();

  await database.schema
    .createTable('paper_entry_intents')
    .addColumn('id', 'varchar(26)', (column) => column.primaryKey())
    .addColumn('wallet_id', 'varchar(26)', (column) =>
      column.notNull().references('paper_wallets.id').onDelete('restrict'),
    )
    .addColumn('opportunity_id', 'varchar(26)', (column) =>
      column.notNull().references('opportunities.id').onDelete('restrict').unique(),
    )
    .addColumn('evaluation_id', 'varchar(26)', (column) =>
      column.notNull().references('opportunity_evaluations.id').onDelete('restrict').unique(),
    )
    .addColumn('source_opportunity_id', 'varchar(64)', (column) => column.notNull().unique())
    .addColumn('control_plane_user_id', 'varchar(64)', (column) => column.notNull())
    .addColumn('network_id', 'varchar(64)', (column) => column.notNull())
    .addColumn('asset_address', 'varchar(128)', (column) => column.notNull())
    .addColumn('status', 'varchar(16)', (column) => column.notNull())
    .addColumn('execution_mode', 'varchar(16)', (column) => column.notNull())
    .addColumn('entry_mode', 'varchar(16)', (column) => column.notNull())
    .addColumn('notional_native', 'numeric(78, 30)', (column) => column.notNull())
    .addColumn('intent_snapshot', 'jsonb', (column) => column.notNull())
    .addColumn('intent_sha256', 'char(64)', (column) => column.notNull())
    .addColumn('authority_snapshot', 'jsonb', (column) => column.notNull())
    .addColumn('authority_sha256', 'char(64)', (column) => column.notNull())
    .addColumn('idempotency_key', 'varchar(128)', (column) => column.notNull().unique())
    .addColumn('correlation_id', 'varchar(128)', (column) => column.notNull())
    .addColumn('traceparent', 'varchar(55)', (column) => column.notNull())
    .addColumn('accepted_at', 'timestamptz', (column) => column.notNull())
    .addColumn('created_at', 'timestamptz', (column) => column.notNull().defaultTo(sql`CURRENT_TIMESTAMP`))
    .addCheckConstraint('paper_entry_intents_status_check', sql`status = 'executed'`)
    .addCheckConstraint('paper_entry_intents_modes_check', sql`
      execution_mode = 'paper' and entry_mode = 'auto'
    `)
    .addCheckConstraint('paper_entry_intents_notional_check', sql`notional_native > 0`)
    .execute();

  await database.schema
    .createTable('paper_orders')
    .addColumn('id', 'varchar(26)', (column) => column.primaryKey())
    .addColumn('intent_id', 'varchar(26)', (column) =>
      column.notNull().references('paper_entry_intents.id').onDelete('restrict').unique(),
    )
    .addColumn('wallet_id', 'varchar(26)', (column) =>
      column.notNull().references('paper_wallets.id').onDelete('restrict'),
    )
    .addColumn('side', 'varchar(8)', (column) => column.notNull())
    .addColumn('order_type', 'varchar(32)', (column) => column.notNull())
    .addColumn('status', 'varchar(16)', (column) => column.notNull())
    .addColumn('asset_address', 'varchar(128)', (column) => column.notNull())
    .addColumn('requested_notional_native', 'numeric(78, 30)', (column) => column.notNull())
    .addColumn('currency', 'varchar(16)', (column) => column.notNull())
    .addColumn('market_snapshot', 'jsonb', (column) => column.notNull())
    .addColumn('created_at', 'timestamptz', (column) => column.notNull().defaultTo(sql`CURRENT_TIMESTAMP`))
    .addCheckConstraint('paper_orders_shape_check', sql`
      side = 'buy' and order_type = 'simulated_market' and status = 'filled' and currency = 'SOL'
    `)
    .execute();

  await database.schema
    .createTable('paper_fills')
    .addColumn('id', 'varchar(26)', (column) => column.primaryKey())
    .addColumn('order_id', 'varchar(26)', (column) =>
      column.notNull().references('paper_orders.id').onDelete('restrict').unique(),
    )
    .addColumn('notional_native', 'numeric(78, 30)', (column) => column.notNull())
    .addColumn('fee_native', 'numeric(78, 30)', (column) => column.notNull())
    .addColumn('fill_price_usd', 'numeric(78, 30)', (column) => column.notNull())
    .addColumn('quantity', 'numeric(78, 30)', (column) => column.notNull())
    .addColumn('quantity_unit', 'varchar(64)', (column) => column.notNull())
    .addColumn('fill_model', 'varchar(64)', (column) => column.notNull())
    .addColumn('executed_at', 'timestamptz', (column) => column.notNull())
    .addColumn('created_at', 'timestamptz', (column) => column.notNull().defaultTo(sql`CURRENT_TIMESTAMP`))
    .addCheckConstraint('paper_fills_values_check', sql`
      notional_native > 0 and fee_native >= 0 and fill_price_usd > 0 and quantity > 0
    `)
    .addCheckConstraint('paper_fills_model_check', sql`
      quantity_unit = 'normalized_position_unit'
      and fill_model = 'observed_mark_normalized_notional_v1'
    `)
    .execute();

  await database.schema
    .createTable('paper_positions')
    .addColumn('id', 'varchar(26)', (column) => column.primaryKey())
    .addColumn('wallet_id', 'varchar(26)', (column) =>
      column.notNull().references('paper_wallets.id').onDelete('restrict'),
    )
    .addColumn('intent_id', 'varchar(26)', (column) =>
      column.notNull().references('paper_entry_intents.id').onDelete('restrict').unique(),
    )
    .addColumn('order_id', 'varchar(26)', (column) =>
      column.notNull().references('paper_orders.id').onDelete('restrict').unique(),
    )
    .addColumn('fill_id', 'varchar(26)', (column) =>
      column.notNull().references('paper_fills.id').onDelete('restrict').unique(),
    )
    .addColumn('opportunity_id', 'varchar(26)', (column) =>
      column.notNull().references('opportunities.id').onDelete('restrict'),
    )
    .addColumn('evaluation_id', 'varchar(26)', (column) =>
      column.notNull().references('opportunity_evaluations.id').onDelete('restrict'),
    )
    .addColumn('control_plane_user_id', 'varchar(64)', (column) => column.notNull())
    .addColumn('network_id', 'varchar(64)', (column) => column.notNull())
    .addColumn('asset_address', 'varchar(128)', (column) => column.notNull())
    .addColumn('symbol', 'varchar(64)')
    .addColumn('state', 'varchar(16)', (column) => column.notNull())
    .addColumn('quantity', 'numeric(78, 30)', (column) => column.notNull())
    .addColumn('quantity_unit', 'varchar(64)', (column) => column.notNull())
    .addColumn('cost_basis_native', 'numeric(78, 30)', (column) => column.notNull())
    .addColumn('entry_price_usd', 'numeric(78, 30)', (column) => column.notNull())
    .addColumn('entry_market_cap_usd', 'numeric(78, 30)', (column) => column.notNull())
    .addColumn('entry_liquidity_usd', 'numeric(78, 30)')
    .addColumn('strategy_snapshot', 'jsonb', (column) => column.notNull())
    .addColumn('strategy_sha256', 'char(64)', (column) => column.notNull())
    .addColumn('authority_snapshot', 'jsonb', (column) => column.notNull())
    .addColumn('authority_sha256', 'char(64)', (column) => column.notNull())
    .addColumn('opened_at', 'timestamptz', (column) => column.notNull())
    .addColumn('closed_at', 'timestamptz')
    .addColumn('created_at', 'timestamptz', (column) => column.notNull().defaultTo(sql`CURRENT_TIMESTAMP`))
    .addColumn('updated_at', 'timestamptz', (column) => column.notNull().defaultTo(sql`CURRENT_TIMESTAMP`))
    .addCheckConstraint('paper_positions_state_check', sql`state in ('open', 'closed')`)
    .addCheckConstraint('paper_positions_values_check', sql`
      quantity > 0 and cost_basis_native > 0 and entry_price_usd > 0 and entry_market_cap_usd > 0
    `)
    .execute();

  await sql`
    create unique index paper_positions_open_asset_unique
    on paper_positions (wallet_id, network_id, asset_address)
    where state = 'open'
  `.execute(database);

  await sql`
    create function assert_paper_ledger_transaction_balanced()
    returns trigger
    language plpgsql
    as $$
    declare
      entry_count integer;
      transaction_balance numeric(78, 30);
      wallet_mismatches integer;
    begin
      select count(*), coalesce(sum(case when direction = 'debit' then amount_native else -amount_native end), 0)
      into entry_count, transaction_balance
      from paper_ledger_entries
      where transaction_id = case when TG_TABLE_NAME = 'paper_ledger_entries' then new.transaction_id else new.id end;

      select count(*)
      into wallet_mismatches
      from paper_ledger_entries
      where transaction_id = case when TG_TABLE_NAME = 'paper_ledger_entries' then new.transaction_id else new.id end
        and wallet_id <> (
          select wallet_id
          from paper_ledger_transactions
          where id = case when TG_TABLE_NAME = 'paper_ledger_entries' then new.transaction_id else new.id end
        );

      if entry_count < 2 or transaction_balance <> 0 or wallet_mismatches <> 0 then
        raise exception 'paper ledger transaction must contain balanced entries for one wallet';
      end if;

      return new;
    end;
    $$
  `.execute(database);

  await sql`
    create constraint trigger paper_ledger_transaction_balanced
    after insert on paper_ledger_transactions
    deferrable initially deferred
    for each row execute function assert_paper_ledger_transaction_balanced()
  `.execute(database);

  await sql`
    create constraint trigger paper_ledger_entry_balanced
    after insert on paper_ledger_entries
    deferrable initially deferred
    for each row execute function assert_paper_ledger_transaction_balanced()
  `.execute(database);

  await sql`
    create function reject_paper_financial_evidence_mutation()
    returns trigger
    language plpgsql
    as $$
    begin
      raise exception 'paper financial evidence is append-only';
    end;
    $$
  `.execute(database);

  for (const table of [
    'paper_wallets',
    'paper_ledger_transactions',
    'paper_ledger_entries',
    'paper_entry_intents',
    'paper_orders',
    'paper_fills',
  ]) {
    await sql.raw(`
      create trigger ${table}_append_only
      before update or delete on ${table}
      for each row execute function reject_paper_financial_evidence_mutation()
    `).execute(database);
  }
}

export async function down(database: Kysely<unknown>): Promise<void> {
  await database.schema.dropTable('paper_positions').ifExists().execute();
  await database.schema.dropTable('paper_fills').ifExists().execute();
  await database.schema.dropTable('paper_orders').ifExists().execute();
  await database.schema.dropTable('paper_entry_intents').ifExists().execute();
  await database.schema.dropTable('paper_ledger_entries').ifExists().execute();
  await database.schema.dropTable('paper_ledger_transactions').ifExists().execute();
  await database.schema.dropTable('paper_wallets').ifExists().execute();
  await sql`drop function if exists assert_paper_ledger_transaction_balanced()`.execute(database);
  await sql`drop function if exists reject_paper_financial_evidence_mutation()`.execute(database);
}
