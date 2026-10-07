import { sql, type Kysely } from 'kysely';
import pino from 'pino';
import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest';

import type { ExecutePaperEntryCommand } from '../../src/application/commands/execute-paper-entry-command.js';
import { ExecutePaperEntryCommandHandler } from '../../src/application/handlers/execute-paper-entry-command-handler.js';
import { RecordOpportunityCommandHandler } from '../../src/application/handlers/record-opportunity-command-handler.js';
import { SOLANA_MAINNET_ID, type OpportunityCommand } from '../../src/contracts/http/opportunity-command.schema.js';
import type { EngineConfig } from '../../src/config/env.js';
import { createDatabase, type Database } from '../../src/infrastructure/database/client.js';
import { CommandInboxRepository } from '../../src/infrastructure/database/repositories/command-inbox-repository.js';
import { OpportunityEvaluationRepository } from '../../src/infrastructure/database/repositories/opportunity-evaluation-repository.js';
import { OpportunityRepository } from '../../src/infrastructure/database/repositories/opportunity-repository.js';
import { OutboxRepository } from '../../src/infrastructure/database/repositories/outbox-repository.js';
import { PaperEntryRepository } from '../../src/infrastructure/database/repositories/paper-entry-repository.js';
import { OpportunityEvaluationDispatcher } from '../../src/workers/opportunity-evaluation-dispatcher.js';
import {
  createTestIdentity,
  resetDatabase,
  startTestEnvironment,
  stopTestEnvironment,
  type TestEnvironment,
} from '../support/test-environment.js';

const traceparent = '00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01';
const now = new Date();
const assetAddress = 'So11111111111111111111111111111111111111112';

describe('engine-owned PAPER financial entry', () => {
  let environment: TestEnvironment;
  let config: EngineConfig;
  let database: Kysely<Database>;
  let commandInbox: CommandInboxRepository;
  let outbox: OutboxRepository;
  let entries: PaperEntryRepository;
  let recordHandler: RecordOpportunityCommandHandler;

  beforeAll(async () => {
    environment = await startTestEnvironment();
    config = createTestIdentity(environment, { PAPER_ENTRY_ENABLED: 'true' }).config;
    database = createDatabase(config);
    commandInbox = new CommandInboxRepository();
    outbox = new OutboxRepository();
    entries = new PaperEntryRepository();
    recordHandler = new RecordOpportunityCommandHandler(
      database,
      commandInbox,
      new OpportunityRepository(),
      outbox,
    );
  });

  beforeEach(async () => {
    await resetDatabase(database);
  });

  afterAll(async () => {
    await database.destroy();
    await stopTestEnvironment(environment);
  });

  it('atomically creates a fresh wallet, balanced ledger, fill, position, lifecycle, and event', async () => {
    const source = await evaluatedOpportunity('101');
    const response = await handler().execute(entryCommand(source));
    const wallet = await database.selectFrom('paper_wallets').selectAll().executeTakeFirstOrThrow();
    const intent = await database.selectFrom('paper_entry_intents').selectAll().executeTakeFirstOrThrow();
    const order = await database.selectFrom('paper_orders').selectAll().executeTakeFirstOrThrow();
    const fill = await database.selectFrom('paper_fills').selectAll().executeTakeFirstOrThrow();
    const position = await database.selectFrom('paper_positions').selectAll().executeTakeFirstOrThrow();
    const lifecycle = await database.selectFrom('paper_position_lifecycles').selectAll().executeTakeFirstOrThrow();
    const event = await database.selectFrom('event_outbox')
      .selectAll()
      .where('event_type', '=', 'paper.entry.executed.v1')
      .executeTakeFirstOrThrow();

    expect(response).toMatchObject({ status: 'accepted', duplicate: false });
    expect(wallet).toMatchObject({ opening_balance_native: '5', currency: 'SOL' });
    expect(intent).toMatchObject({
      notional_native: '0.1',
      authority_snapshot: entryCommand(source).body.authority,
    });
    expect(order).toMatchObject({ status: 'filled', requested_notional_native: '0.1' });
    expect(fill).toMatchObject({
      notional_native: '0.1',
      fill_price_usd: '0.000001',
      fee_native: '0',
    });
    expect(position).toMatchObject({
      id: response.positionId,
      cost_basis_native: '0.1',
      state: 'open',
    });
    expect(lifecycle).toMatchObject({
      id: response.positionId,
      source_position_id: response.positionId,
      lifecycle_version: 0,
      state: 'open',
    });
    expect(await entries.accountBalance(database, wallet.id, 'available')).toBe('4.9');
    expect(await entries.accountBalance(database, wallet.id, 'invested')).toBe('0.1');
    expect(await unbalancedLedgerTransactions()).toBe(0);
    expect(event.envelope).toMatchObject({
      event_type: 'paper.entry.executed.v1',
      aggregate_id: response.positionId,
      causation_id: response.operationId,
      payload: {
        wallet: {
          opening_balance_native: '5',
          available_balance_native: '4.9',
          invested_balance_native: '0.1',
        },
        position: { cost_basis_native: '0.1' },
      },
    });
    await expect(database.insertInto('paper_ledger_entries').values({
      id: '01M50000000000000000000099',
      transaction_id: wallet.id === response.walletId
        ? (await database.selectFrom('paper_ledger_transactions')
          .select('id')
          .where('transaction_type', '=', 'entry')
          .executeTakeFirstOrThrow()).id
        : 'invalid',
      wallet_id: wallet.id,
      account: 'opening_equity',
      direction: 'debit',
      amount_native: '0.01',
      currency: 'SOL',
    }).execute()).rejects.toThrow(/balanced/);
  });

  it('reuses the original wallet and result on exact replay without duplicating financial evidence', async () => {
    const source = await evaluatedOpportunity('102');
    const firstCommand = entryCommand(source);
    const first = await handler().execute(firstCommand);
    const second = await handler().execute({ ...firstCommand, authJti: 'paper-entry-jti-replay' });

    expect(second).toEqual({ ...first, duplicate: true });
    expect(await count('paper_wallets')).toBe(1);
    expect(await count('paper_entry_intents')).toBe(1);
    expect(await count('paper_positions')).toBe(1);
    expect(await countEvents('paper.entry.executed.v1')).toBe(1);

    const secondAsset = 'So22222222222222222222222222222222222222222';
    const secondSource = await evaluatedOpportunity('1022', 'b'.repeat(64), secondAsset);
    await handler().execute(entryCommand(secondSource, 'paper-entry-1022'));
    const wallet = await database.selectFrom('paper_wallets').select('id').executeTakeFirstOrThrow();
    const openingTransactions = await database.selectFrom('paper_ledger_transactions')
      .select(sql.raw<number>('count(*)::int').as('count'))
      .where('transaction_type', '=', 'opening_balance')
      .executeTakeFirstOrThrow();

    expect(await count('paper_wallets')).toBe(1);
    expect(openingTransactions.count).toBe(1);
    expect(await count('paper_positions')).toBe(2);
    expect(await entries.accountBalance(database, wallet.id, 'available')).toBe('4.8');
  });

  it('rejects same-key different-body and concurrent duplicates without double debit', async () => {
    const source = await evaluatedOpportunity('103');
    const command = entryCommand(source);
    const changed = {
      ...command,
      authJti: 'changed-jti',
      body: {
        ...command.body,
        asset: { ...command.body.asset, symbol: 'CHANGED' },
      },
    };

    await handler().execute(command);
    await expect(handler().execute(changed)).rejects.toMatchObject({ code: 'IDEMPOTENCY_CONFLICT' });
    await expect(handler().execute({
      ...command,
      idempotencyKey: 'paper-entry-reused-jti',
    })).rejects.toMatchObject({ code: 'AUTH_REPLAYED' });


    await resetDatabase(database);
    const concurrentSource = await evaluatedOpportunity('104');
    const concurrent = entryCommand(concurrentSource);
    const results = await Promise.all([
      handler().execute(concurrent),
      handler().execute({ ...concurrent, authJti: 'concurrent-jti-2' }),
      handler().execute({ ...concurrent, authJti: 'concurrent-jti-3' }),
    ]);
    const wallet = await database.selectFrom('paper_wallets').select('id').executeTakeFirstOrThrow();

    expect(new Set(results.map((result) => result.positionId)).size).toBe(1);
    expect(await entries.accountBalance(database, wallet.id, 'available')).toBe('4.9');
    expect(await count('paper_positions')).toBe(1);
  });

  it('rejects invalid and stale evaluations without partial financial writes', async () => {
    const invalidSource = await evaluatedOpportunity('1041');
    const invalid = entryCommand(invalidSource, 'invalid-evaluation-key');
    invalid.body.source.evaluation_result_sha256 = 'f'.repeat(64);

    await expect(handler().execute(invalid)).rejects.toMatchObject({
      code: 'PAPER_ENTRY_EVALUATION_INVALID',
    });
    expect(await count('paper_wallets')).toBe(0);
    const missingOpportunity = entryCommand(invalidSource, 'invalid-opportunity-key');
    missingOpportunity.body.source.engine_opportunity_id = '01M50000000000000000000999';
    await expect(handler().execute(missingOpportunity)).rejects.toMatchObject({
      code: 'PAPER_ENTRY_CORRELATION_INVALID',
    });
    expect(await count('paper_wallets')).toBe(0);


    await resetDatabase(database);
    const staleSource = await evaluatedOpportunity('1042');
    const future = new Date(now.getTime() + 600_000);
    const stale = entryCommand(staleSource, 'stale-evaluation-key');
    stale.body.entry.intent_created_at = future.toISOString();
    stale.body.entry.expires_at = new Date(future.getTime() + 300_000).toISOString();

    await expect(handler({}, () => future).execute(stale)).rejects.toMatchObject({
      code: 'PAPER_ENTRY_EVALUATION_STALE',
    });
    expect(await count('paper_wallets')).toBe(0);
    expect(await count('paper_positions')).toBe(0);
  });

  it('rejects insufficient funds, a stale intent, and a duplicate open asset without partial writes', async () => {
    const insufficientSource = await evaluatedOpportunity('105');
    const insufficient = handler({ openingBalanceNative: '0.05' });
    await expect(insufficient.execute(entryCommand(insufficientSource))).rejects.toMatchObject({
      code: 'PAPER_FUNDS_INSUFFICIENT',
    });
    expect(await count('paper_wallets')).toBe(0);
    expect(await count('paper_positions')).toBe(0);

    await resetDatabase(database);
    const staleSource = await evaluatedOpportunity('106');
    const stale = entryCommand(staleSource);
    stale.body.entry.expires_at = '2026-10-07T11:59:59.000Z';
    await expect(handler().execute(stale)).rejects.toMatchObject({ code: 'PAPER_ENTRY_INTENT_STALE' });
    expect(await count('command_inbox')).toBe(1);
    expect(await count('paper_wallets')).toBe(0);

    await resetDatabase(database);
    const firstSource = await evaluatedOpportunity('107');
    await handler().execute(entryCommand(firstSource));
    const secondSource = await evaluatedOpportunity('108', 'b'.repeat(64));
    await expect(handler().execute(entryCommand(secondSource, 'second-entry-key'))).rejects.toMatchObject({
      code: 'PAPER_POSITION_ALREADY_OPEN',
    });
    expect(await count('paper_positions')).toBe(1);
  });

  it('rolls back all financial and lifecycle state when outbox creation fails', async () => {
    class FailingOutbox extends OutboxRepository {
      public override enqueue(): Promise<void> {
        return Promise.reject(new Error('forced entry outbox failure'));
      }
    }

    const source = await evaluatedOpportunity('109');
    const failing = new ExecutePaperEntryCommandHandler(
      database,
      commandInbox,
      entries,
      new FailingOutbox(),
      policy(),
      undefined,
      () => now,
    );

    await expect(failing.execute(entryCommand(source))).rejects.toThrow('forced entry outbox failure');
    expect(await count('paper_wallets')).toBe(0);
    expect(await count('paper_ledger_entries')).toBe(0);
    expect(await count('paper_entry_intents')).toBe(0);
    expect(await count('paper_positions')).toBe(0);
    expect(await count('paper_position_lifecycles')).toBe(0);
  });

  function handler(
    overrides: Partial<ReturnType<typeof policy>> = {},
    clock: () => Date = () => now,
  ): ExecutePaperEntryCommandHandler {
    return new ExecutePaperEntryCommandHandler(
      database,
      commandInbox,
      entries,
      outbox,
      { ...policy(), ...overrides },
      undefined,
      clock,
    );
  }

  async function evaluatedOpportunity(
    sourceId: string,
    discoveryKey = 'a'.repeat(64),
    address = assetAddress,
  ): Promise<{
    readonly opportunityId: string;
    readonly sourceOpportunityId: string;
    readonly evaluationId: string;
    readonly resultSha256: string;
    readonly assetAddress: string;
  }> {
    const recorded = await recordHandler.execute({
      idempotencyKey: `opportunity-${sourceId}`,
      authJti: `opportunity-jti-${sourceId}`,
      subject: 'laravel-service',
      correlationId: `correlation-${sourceId}`,
      traceparent,
      body: opportunity(sourceId, discoveryKey, address),
    });
    const dispatcher = new OpportunityEvaluationDispatcher(
      config,
      database,
      new OpportunityEvaluationRepository(),
      outbox,
      pino({ level: 'silent' }),
    );
    await dispatcher.dispatchBatch();
    const evaluation = await database.selectFrom('opportunity_evaluations')
      .select(['id', 'result_sha256'])
      .where('opportunity_id', '=', recorded.opportunityId)
      .executeTakeFirstOrThrow();

    return {
      opportunityId: recorded.opportunityId,
      sourceOpportunityId: sourceId,
      evaluationId: evaluation.id,
      resultSha256: evaluation.result_sha256,
      assetAddress: address,
    };
  }

  async function count(table: keyof Database): Promise<number> {
    const result = await database.selectFrom(table)
      .select(sql.raw<number>('count(*)::int').as('count'))
      .executeTakeFirstOrThrow();

    return result.count;
  }

  async function countEvents(eventType: string): Promise<number> {
    const result = await database.selectFrom('event_outbox')
      .select(sql.raw<number>('count(*)::int').as('count'))
      .where('event_type', '=', eventType)
      .executeTakeFirstOrThrow();

    return result.count;
  }

  async function unbalancedLedgerTransactions(): Promise<number> {
    const result = await sql<{ readonly count: number }>`
      select count(*)::int as count
      from (
        select transaction_id
        from paper_ledger_entries
        group by transaction_id
        having sum(case when direction = 'debit' then amount_native else -amount_native end) <> 0
      ) unbalanced
    `.execute(database);

    return result.rows[0]?.count ?? 0;
  }
});

function policy(): {
  readonly enabled: boolean;
  readonly openingBalanceNative: string;
  readonly entryNotionalNative: string;
  readonly intentMaxAgeSeconds: number;
} {
  return {
    enabled: true,
    openingBalanceNative: '5',
    entryNotionalNative: '0.1',
    intentMaxAgeSeconds: 300,
  };
}

function entryCommand(
  source: { readonly opportunityId: string; readonly sourceOpportunityId: string; readonly evaluationId: string; readonly resultSha256: string; readonly assetAddress: string },
  idempotencyKey = 'paper-entry-101',
): ExecutePaperEntryCommand {
  return {
    idempotencyKey,
    authJti: `jti-${idempotencyKey}`,
    subject: 'laravel-service',
    correlationId: 'paper-entry-correlation',
    traceparent,
    body: {
      schema_version: 1,
      source: {
        system: 'meme-scanner-laravel',
        trade_opportunity_id: source.sourceOpportunityId,
        engine_opportunity_id: source.opportunityId,
        evaluation_id: source.evaluationId,
        evaluation_result_sha256: source.resultSha256,
      },
      subject: { control_plane_user_id: '7' },
      network: { id: SOLANA_MAINNET_ID, native_currency: 'SOL' },
      asset: { address: source.assetAddress, symbol: 'MEME' },
      entry: {
        requested_notional_native: '0.1',
        market_cap_usd: '12000',
        price_usd: '0.000001',
        liquidity_usd: '3000.25',
        intent_created_at: now.toISOString(),
        expires_at: new Date(now.getTime() + 300_000).toISOString(),
      },
      authority: {
        execution_mode: 'paper',
        entry_mode: 'auto',
        trading_enabled: true,
        preference_version: 'preference-1',
        kill_switch_engaged: false,
        kill_switch_version: 'kill-switch-1',
        strategy: {
          stop_loss_percent: '10',
          protection_level_1_percent: '100',
          protection_level_2_percent: '200',
        },
        risk: { trade_size_native: '0.1', source: 'laravel-control-plane' },
        effective_policy: { key: 'engine-paper-entry', version: 1 },
      },
    },
  };
}

function opportunity(sourceId: string, discoveryKey: string, address: string): OpportunityCommand {
  return {
    schema_version: 1,
    source: {
      system: 'meme-scanner-laravel',
      opportunity_id: sourceId,
      discovery_key: discoveryKey,
      scanner: 'new-token',
    },
    subject: { control_plane_user_id: '7' },
    network: { id: SOLANA_MAINNET_ID },
    asset: { address, symbol: 'MEME' },
    market_snapshot: {
      price_usd: { value: '0.000001', provider: 'birdeye' },
      market_cap_usd: { value: '12000', provider: 'birdeye' },
      liquidity_usd: { value: '3000.25', provider: 'birdeye' },
      volume_usd: { value: '900.5', provider: 'birdeye', window: '1m' },
    },
    qualification: {
      qualified_at: '2026-10-07T11:59:00.000Z',
      discovery_market_cap_usd: '10000',
      move_since_discovery_percent: '20',
      classification: 'strong',
    },
    security: {
      status: 'passed',
      provider: 'goplus',
      passed: true,
      score: 95,
      risks: [],
    },
  };
}
