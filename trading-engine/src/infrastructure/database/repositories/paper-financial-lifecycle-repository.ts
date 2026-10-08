import { sql, type Insertable, type Kysely, type Selectable, type Transaction } from 'kysely';

import type { PaperFinancialObservation } from '../../../contracts/http/paper-financial-observation-command.schema.js';
import type {
  Database,
  PaperExitSettlementTable,
  PaperFinancialPositionTable,
  PaperWalletTable,
} from '../client.js';

type DatabaseTransaction = Transaction<Database>;
export type PaperExitSettlementRecord = Selectable<PaperExitSettlementTable>;
export type PaperFinancialPositionRecord = Selectable<PaperFinancialPositionTable>;

export interface PaperExitIdentifiers {
  readonly orderId: string;
  readonly fillId: string;
  readonly settlementId: string;
  readonly ledgerTransactionId: string;
  readonly availableEntryId: string;
  readonly investedEntryId: string;
  readonly realizedPnlEntryId: string;
}

export interface PaperExitPersistence {
  readonly ids: PaperExitIdentifiers;
  readonly position: PaperFinancialPositionRecord;
  readonly wallet: Selectable<PaperWalletTable>;
  readonly decisionId: string;
  readonly eventId: string;
  readonly command: PaperFinancialObservation;
  readonly idempotencyKey: string;
  readonly correlationId: string;
  readonly traceparent: string;
  readonly costBasisNative: string;
  readonly proceedsNative: string;
  readonly realizedPnlNative: string;
  readonly observedMultiple: string;
  readonly resultSha256: string;
  readonly settledAt: Date;
}

export class PaperFinancialLifecycleRepository {
  public async lockPosition(
    transaction: DatabaseTransaction,
    positionId: string,
  ): Promise<PaperFinancialPositionRecord | undefined> {
    return transaction.selectFrom('paper_positions')
      .selectAll()
      .where('id', '=', positionId)
      .forUpdate()
      .executeTakeFirst();
  }

  public async lockWallet(
    transaction: DatabaseTransaction,
    walletId: string,
  ): Promise<Selectable<PaperWalletTable>> {
    return transaction.selectFrom('paper_wallets')
      .selectAll()
      .where('id', '=', walletId)
      .forUpdate()
      .executeTakeFirstOrThrow();
  }

  public async accountBalance(
    database: Kysely<Database>,
    walletId: string,
    account: 'available' | 'invested',
  ): Promise<string> {
    const result = await sql<{ readonly balance: string }>`
      select coalesce(sum(case when direction = 'debit' then amount_native else -amount_native end), 0)::numeric as balance
      from paper_ledger_entries
      where wallet_id = ${walletId} and account = ${account}
    `.execute(database);

    return result.rows[0]?.balance ?? '0';
  }

  public async realizedPnlBalance(
    database: Kysely<Database>,
    walletId: string,
  ): Promise<string> {
    const result = await sql<{ readonly balance: string }>`
      select coalesce(sum(case when direction = 'credit' then amount_native else -amount_native end), 0)::numeric as balance
      from paper_ledger_entries
      where wallet_id = ${walletId} and account = 'realized_pnl'
    `.execute(database);

    return result.rows[0]?.balance ?? '0';
  }

  public async findSettlementByDecision(
    transaction: DatabaseTransaction,
    decisionId: string,
  ): Promise<PaperExitSettlementRecord | undefined> {
    return transaction.selectFrom('paper_exit_settlements')
      .selectAll()
      .where('decision_id', '=', decisionId)
      .executeTakeFirst();
  }

  public async persistExit(
    transaction: DatabaseTransaction,
    input: PaperExitPersistence,
  ): Promise<void> {
    await transaction.insertInto('paper_exit_orders').values({
      id: input.ids.orderId,
      position_id: input.position.id,
      wallet_id: input.wallet.id,
      side: 'sell',
      order_type: 'simulated_market',
      status: 'filled',
      asset_address: input.position.asset_address,
      quantity: input.position.quantity,
      currency: 'SOL',
      market_snapshot: input.command.market,
    }).execute();

    await transaction.insertInto('paper_exit_fills').values({
      id: input.ids.fillId,
      order_id: input.ids.orderId,
      cost_basis_native: input.costBasisNative,
      proceeds_native: input.proceedsNative,
      realized_pnl_native: input.realizedPnlNative,
      exit_price_usd: input.command.market.price_usd,
      exit_market_cap_usd: input.command.market.market_cap_usd,
      observed_multiple: input.observedMultiple,
      quantity: input.position.quantity,
      quantity_unit: input.position.quantity_unit,
      fill_model: 'observed_market_cap_ratio_v1',
      executed_at: input.settledAt,
    }).execute();

    await transaction.insertInto('paper_ledger_transactions').values({
      id: input.ids.ledgerTransactionId,
      wallet_id: input.wallet.id,
      transaction_type: 'exit_settlement',
      reference_id: `paper:exit:${input.position.id}:v1`,
      idempotency_key: input.idempotencyKey,
      correlation_id: input.correlationId,
      occurred_at: input.settledAt,
    }).execute();

    const entries: Insertable<Database['paper_ledger_entries']>[] = [
      {
        id: input.ids.availableEntryId,
        transaction_id: input.ids.ledgerTransactionId,
        wallet_id: input.wallet.id,
        account: 'available',
        direction: 'debit',
        amount_native: input.proceedsNative,
        currency: 'SOL',
      },
      {
        id: input.ids.investedEntryId,
        transaction_id: input.ids.ledgerTransactionId,
        wallet_id: input.wallet.id,
        account: 'invested',
        direction: 'credit',
        amount_native: input.costBasisNative,
        currency: 'SOL',
      },
    ];
    if (input.realizedPnlNative !== '0') {
      const loss = input.realizedPnlNative.startsWith('-');
      entries.push({
        id: input.ids.realizedPnlEntryId,
        transaction_id: input.ids.ledgerTransactionId,
        wallet_id: input.wallet.id,
        account: 'realized_pnl',
        direction: loss ? 'debit' : 'credit',
        amount_native: loss ? input.realizedPnlNative.slice(1) : input.realizedPnlNative,
        currency: 'SOL',
      });
    }
    await transaction.insertInto('paper_ledger_entries').values(entries).execute();

    await transaction.insertInto('paper_exit_settlements').values({
      id: input.ids.settlementId,
      position_id: input.position.id,
      decision_id: input.decisionId,
      order_id: input.ids.orderId,
      fill_id: input.ids.fillId,
      ledger_transaction_id: input.ids.ledgerTransactionId,
      event_id: input.eventId,
      cost_basis_native: input.costBasisNative,
      proceeds_native: input.proceedsNative,
      realized_pnl_native: input.realizedPnlNative,
      result_sha256: input.resultSha256,
      correlation_id: input.correlationId,
      traceparent: input.traceparent,
      settled_at: input.settledAt,
    }).execute();

    await transaction.updateTable('paper_positions').set({
      state: 'closed',
      exit_price_usd: input.command.market.price_usd,
      exit_market_cap_usd: input.command.market.market_cap_usd,
      exit_proceeds_native: input.proceedsNative,
      realized_pnl_native: input.realizedPnlNative,
      closed_at: input.settledAt,
      updated_at: input.settledAt,
    }).where('id', '=', input.position.id).where('state', '=', 'open').executeTakeFirstOrThrow();
  }
}
