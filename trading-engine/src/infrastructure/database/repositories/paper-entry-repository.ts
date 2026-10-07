import { sql, type Kysely, type Selectable, type Transaction } from 'kysely';

import type { PaperEntryCommandBody } from '../../../contracts/http/paper-entry-command.schema.js';
import type {
  Database,
  PaperFinancialPositionTable,
  PaperWalletTable,
} from '../client.js';
import type { EngineId } from '../../../shared/ids/id.js';

type DatabaseTransaction = Transaction<Database>;
export type PaperWalletRecord = Selectable<PaperWalletTable>;
export type PaperFinancialPositionRecord = Selectable<PaperFinancialPositionTable>;

export interface PaperEntryIdentifiers {
  readonly walletId: EngineId;
  readonly openingTransactionId: EngineId;
  readonly openingAvailableEntryId: EngineId;
  readonly openingEquityEntryId: EngineId;
  readonly intentId: EngineId;
  readonly orderId: EngineId;
  readonly fillId: EngineId;
  readonly positionId: EngineId;
  readonly entryTransactionId: EngineId;
  readonly entryAvailableEntryId: EngineId;
  readonly entryInvestedEntryId: EngineId;
}

export interface PaperEntryPersistence {
  readonly command: PaperEntryCommandBody;
  readonly ids: PaperEntryIdentifiers;
  readonly idempotencyKey: string;
  readonly correlationId: string;
  readonly traceparent: string;
  readonly openingBalanceNative: string;
  readonly notionalNative: string;
  readonly intentSha256: string;
  readonly authoritySha256: string;
  readonly strategySha256: string;
  readonly acceptedAt: Date;
}

export class PaperEntryRepository {
  public async establishWallet(
    transaction: DatabaseTransaction,
    command: PaperEntryCommandBody,
    ids: PaperEntryIdentifiers,
    openingBalanceNative: string,
    correlationId: string,
    acceptedAt: Date,
  ): Promise<PaperWalletRecord> {
    const openingReference = this.openingReference(command);
    const inserted = await transaction
      .insertInto('paper_wallets')
      .values({
        id: ids.walletId,
        control_plane_user_id: command.subject.control_plane_user_id,
        network_id: command.network.id,
        currency: 'SOL',
        opening_balance_native: openingBalanceNative,
        opening_reference: openingReference,
      })
      .onConflict((conflict) => conflict
        .columns(['control_plane_user_id', 'network_id', 'currency'])
        .doNothing())
      .returningAll()
      .executeTakeFirst();

    if (inserted !== undefined) {
      await transaction.insertInto('paper_ledger_transactions').values({
        id: ids.openingTransactionId,
        wallet_id: inserted.id,
        transaction_type: 'opening_balance',
        reference_id: openingReference,
        idempotency_key: openingReference,
        correlation_id: correlationId,
        occurred_at: acceptedAt,
      }).execute();
      await transaction.insertInto('paper_ledger_entries').values([
        {
          id: ids.openingAvailableEntryId,
          transaction_id: ids.openingTransactionId,
          wallet_id: inserted.id,
          account: 'available',
          direction: 'debit',
          amount_native: openingBalanceNative,
          currency: 'SOL',
        },
        {
          id: ids.openingEquityEntryId,
          transaction_id: ids.openingTransactionId,
          wallet_id: inserted.id,
          account: 'opening_equity',
          direction: 'credit',
          amount_native: openingBalanceNative,
          currency: 'SOL',
        },
      ]).execute();

      return inserted;
    }

    const wallet = await transaction
      .selectFrom('paper_wallets')
      .selectAll()
      .where('control_plane_user_id', '=', command.subject.control_plane_user_id)
      .where('network_id', '=', command.network.id)
      .where('currency', '=', 'SOL')
      .forUpdate()
      .executeTakeFirstOrThrow();

    if (wallet.opening_balance_native !== openingBalanceNative
      || wallet.opening_reference !== openingReference) {
      throw new Error('Existing PAPER wallet opening policy does not match the active policy');
    }

    return wallet;
  }

  public async lockWallet(
    transaction: DatabaseTransaction,
    walletId: string,
  ): Promise<PaperWalletRecord> {
    return transaction
      .selectFrom('paper_wallets')
      .selectAll()
      .where('id', '=', walletId)
      .forUpdate()
      .executeTakeFirstOrThrow();
  }

  public async accountBalance(
    connection: Kysely<Database> | DatabaseTransaction,
    walletId: string,
    account: 'available' | 'invested',
  ): Promise<string> {
    const result = await sql<{ readonly balance: string }>`
      select coalesce(sum(case when direction = 'debit' then amount_native else -amount_native end), 0)::numeric as balance
      from paper_ledger_entries
      where wallet_id = ${walletId} and account = ${account}
    `.execute(connection);

    return result.rows[0]?.balance ?? '0';
  }

  public async findOpenPosition(
    transaction: DatabaseTransaction,
    walletId: string,
    assetAddress: string,
  ): Promise<PaperFinancialPositionRecord | undefined> {
    return transaction
      .selectFrom('paper_positions')
      .selectAll()
      .where('wallet_id', '=', walletId)
      .where('asset_address', '=', assetAddress)
      .where('state', '=', 'open')
      .executeTakeFirst();
  }

  public async persistEntry(
    transaction: DatabaseTransaction,
    input: PaperEntryPersistence,
    wallet: PaperWalletRecord,
  ): Promise<void> {
    const body = input.command;
    const marketSnapshot = {
      market_cap_usd: body.entry.market_cap_usd,
      price_usd: body.entry.price_usd,
      ...(body.entry.liquidity_usd === undefined
        ? {}
        : { liquidity_usd: body.entry.liquidity_usd }),
      observed_at: body.entry.intent_created_at,
    };

    await transaction.insertInto('paper_entry_intents').values({
      id: input.ids.intentId,
      wallet_id: wallet.id,
      opportunity_id: body.source.engine_opportunity_id,
      evaluation_id: body.source.evaluation_id,
      source_opportunity_id: body.source.trade_opportunity_id,
      control_plane_user_id: body.subject.control_plane_user_id,
      network_id: body.network.id,
      asset_address: body.asset.address,
      status: 'executed',
      execution_mode: 'paper',
      entry_mode: 'auto',
      notional_native: input.notionalNative,
      intent_snapshot: body,
      intent_sha256: input.intentSha256,
      authority_snapshot: body.authority,
      authority_sha256: input.authoritySha256,
      idempotency_key: input.idempotencyKey,
      correlation_id: input.correlationId,
      traceparent: input.traceparent,
      accepted_at: input.acceptedAt,
    }).execute();

    await transaction.insertInto('paper_orders').values({
      id: input.ids.orderId,
      intent_id: input.ids.intentId,
      wallet_id: wallet.id,
      side: 'buy',
      order_type: 'simulated_market',
      status: 'filled',
      asset_address: body.asset.address,
      requested_notional_native: input.notionalNative,
      currency: 'SOL',
      market_snapshot: marketSnapshot,
    }).execute();

    await transaction.insertInto('paper_fills').values({
      id: input.ids.fillId,
      order_id: input.ids.orderId,
      notional_native: input.notionalNative,
      fee_native: '0',
      fill_price_usd: body.entry.price_usd,
      quantity: '1',
      quantity_unit: 'normalized_position_unit',
      fill_model: 'observed_mark_normalized_notional_v1',
      executed_at: input.acceptedAt,
    }).execute();

    await transaction.insertInto('paper_positions').values({
      id: input.ids.positionId,
      wallet_id: wallet.id,
      intent_id: input.ids.intentId,
      order_id: input.ids.orderId,
      fill_id: input.ids.fillId,
      opportunity_id: body.source.engine_opportunity_id,
      evaluation_id: body.source.evaluation_id,
      control_plane_user_id: body.subject.control_plane_user_id,
      network_id: body.network.id,
      asset_address: body.asset.address,
      symbol: body.asset.symbol ?? null,
      state: 'open',
      quantity: '1',
      quantity_unit: 'normalized_position_unit',
      cost_basis_native: input.notionalNative,
      entry_price_usd: body.entry.price_usd,
      entry_market_cap_usd: body.entry.market_cap_usd,
      entry_liquidity_usd: body.entry.liquidity_usd ?? null,
      strategy_snapshot: body.authority.strategy,
      strategy_sha256: input.strategySha256,
      authority_snapshot: body.authority,
      authority_sha256: input.authoritySha256,
      opened_at: input.acceptedAt,
      closed_at: null,
    }).execute();

    await transaction.insertInto('paper_ledger_transactions').values({
      id: input.ids.entryTransactionId,
      wallet_id: wallet.id,
      transaction_type: 'entry',
      reference_id: `paper:entry:${body.source.engine_opportunity_id}:v1`,
      idempotency_key: input.idempotencyKey,
      correlation_id: input.correlationId,
      occurred_at: input.acceptedAt,
    }).execute();
    await transaction.insertInto('paper_ledger_entries').values([
      {
        id: input.ids.entryAvailableEntryId,
        transaction_id: input.ids.entryTransactionId,
        wallet_id: wallet.id,
        account: 'available',
        direction: 'credit',
        amount_native: input.notionalNative,
        currency: 'SOL',
      },
      {
        id: input.ids.entryInvestedEntryId,
        transaction_id: input.ids.entryTransactionId,
        wallet_id: wallet.id,
        account: 'invested',
        direction: 'debit',
        amount_native: input.notionalNative,
        currency: 'SOL',
      },
    ]).execute();

    await transaction.insertInto('paper_position_lifecycles').values({
      id: input.ids.positionId,
      source_position_id: input.ids.positionId,
      source_opportunity_id: body.source.trade_opportunity_id,
      opportunity_id: body.source.engine_opportunity_id,
      control_plane_user_id: body.subject.control_plane_user_id,
      network_id: body.network.id,
      asset_address: body.asset.address,
      symbol: body.asset.symbol ?? null,
      initial_investment_native: input.notionalNative,
      entry_market_cap_usd: body.entry.market_cap_usd,
      entry_price_usd: body.entry.price_usd,
      entry_liquidity_usd: body.entry.liquidity_usd ?? null,
      strategy_snapshot: body.authority.strategy,
      strategy_sha256: input.strategySha256,
      policy_key: 'laravel-paper-protection',
      policy_version: 1,
      lifecycle_version: 0,
      last_observation_id: null,
      last_observation_sequence: 0,
      last_market_cap_usd: null,
      last_price_usd: null,
      last_liquidity_usd: null,
      peak_market_cap_usd: body.entry.market_cap_usd,
      peak_multiple: '1',
      max_drawdown_percent: '0',
      protection_state: 'none',
      state: 'open',
      registered_at: input.acceptedAt,
      last_observed_at: null,
      terminal_at: null,
    }).execute();
  }

  private openingReference(command: PaperEntryCommandBody): string {
    return `paper:wallet:opening:${command.subject.control_plane_user_id}:${command.network.id}:SOL:v1`;
  }
}
