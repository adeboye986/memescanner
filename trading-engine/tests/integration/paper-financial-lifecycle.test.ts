import { sql, type Kysely } from 'kysely';
import pino from 'pino';
import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest';

import type { ExecutePaperEntryCommand } from '../../src/application/commands/execute-paper-entry-command.js';
import type { ObservePaperFinancialPositionCommand } from '../../src/application/commands/observe-paper-financial-position-command.js';
import { ExecutePaperEntryCommandHandler } from '../../src/application/handlers/execute-paper-entry-command-handler.js';
import { ObservePaperFinancialPositionCommandHandler } from '../../src/application/handlers/observe-paper-financial-position-command-handler.js';
import { RecordOpportunityCommandHandler } from '../../src/application/handlers/record-opportunity-command-handler.js';
import { SOLANA_MAINNET_ID, type OpportunityCommand } from '../../src/contracts/http/opportunity-command.schema.js';
import type { EngineConfig } from '../../src/config/env.js';
import { createDatabase, type Database } from '../../src/infrastructure/database/client.js';
import { CommandInboxRepository } from '../../src/infrastructure/database/repositories/command-inbox-repository.js';
import { OpportunityEvaluationRepository } from '../../src/infrastructure/database/repositories/opportunity-evaluation-repository.js';
import { OpportunityRepository } from '../../src/infrastructure/database/repositories/opportunity-repository.js';
import { OutboxRepository } from '../../src/infrastructure/database/repositories/outbox-repository.js';
import { PaperEntryRepository } from '../../src/infrastructure/database/repositories/paper-entry-repository.js';
import { PaperFinancialLifecycleRepository } from '../../src/infrastructure/database/repositories/paper-financial-lifecycle-repository.js';
import { PaperPositionLifecycleRepository } from '../../src/infrastructure/database/repositories/paper-position-lifecycle-repository.js';
import { PaperPositionMonitoringRepository } from '../../src/infrastructure/database/repositories/paper-position-monitoring-repository.js';
import { OpportunityEvaluationDispatcher } from '../../src/workers/opportunity-evaluation-dispatcher.js';
import {
  createTestIdentity,
  resetDatabase,
  startTestEnvironment,
  stopTestEnvironment,
  type TestEnvironment,
} from '../support/test-environment.js';

const traceparent = '00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01';
let testClock = new Date(0);
const assetAddress = 'So11111111111111111111111111111111111111112';

describe('engine-owned PAPER financial lifecycle', () => {
  let environment: TestEnvironment;
  let config: EngineConfig;
  let database: Kysely<Database>;
  let commandInbox: CommandInboxRepository;
  let outbox: OutboxRepository;
  let financials: PaperFinancialLifecycleRepository;
  let recordHandler: RecordOpportunityCommandHandler;

  beforeAll(async () => {
    environment = await startTestEnvironment();
    config = createTestIdentity(environment, {
      PAPER_ENTRY_ENABLED: 'true',
      PAPER_FINANCIAL_LIFECYCLE_ENABLED: 'true',
    }).config;
    database = createDatabase(config);
    commandInbox = new CommandInboxRepository();
    outbox = new OutboxRepository();
    financials = new PaperFinancialLifecycleRepository();
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

  it('persists HOLD evidence and advances lifecycle without financial movement', async () => {
    const positionId = await openPosition('201');
    const wallet = await database.selectFrom('paper_wallets').select('id').executeTakeFirstOrThrow();
    const beforeEntries = await count('paper_ledger_entries');
    const response = await lifecycleHandler().execute(observation(positionId, 1, '13000'));
    const lifecycle = await database.selectFrom('paper_position_lifecycles').selectAll().executeTakeFirstOrThrow();
    const decision = await database.selectFrom('paper_position_lifecycle_decisions').selectAll().executeTakeFirstOrThrow();
    const event = await database.selectFrom('event_outbox')
      .selectAll()
      .where('event_type', '=', 'paper.position.held.v1')
      .executeTakeFirstOrThrow();

    expect(response).toMatchObject({ positionId, decision: 'HOLD', settlementId: null, duplicate: false });
    expect(lifecycle).toMatchObject({ lifecycle_version: 1, last_observation_sequence: 1, state: 'open' });
    expect(decision).toMatchObject({ decision: 'HOLD', observation_sequence: 1 });
    expect(await count('paper_ledger_entries')).toBe(beforeEntries);
    expect(await financials.accountBalance(database, wallet.id, 'available')).toBe('4.9');
    expect(await financials.accountBalance(database, wallet.id, 'invested')).toBe('0.1');
    expect(event.envelope).toMatchObject({
      aggregate_id: positionId,
      aggregate_version: 2,
      payload: { decision: 'HOLD', lifecycle_version: 1 },
    });
  });

  it('settles EXIT atomically with balanced ledger, realized loss, closure, and immutable event', async () => {
    const positionId = await openPosition('202');
    const wallet = await database.selectFrom('paper_wallets').select('id').executeTakeFirstOrThrow();
    const response = await lifecycleHandler().execute(observation(positionId, 1, '10200'));
    const position = await database.selectFrom('paper_positions').selectAll().executeTakeFirstOrThrow();
    const lifecycle = await database.selectFrom('paper_position_lifecycles').selectAll().executeTakeFirstOrThrow();
    const settlement = await database.selectFrom('paper_exit_settlements').selectAll().executeTakeFirstOrThrow();
    const fill = await database.selectFrom('paper_exit_fills').selectAll().executeTakeFirstOrThrow();
    const event = await database.selectFrom('event_outbox')
      .selectAll()
      .where('event_type', '=', 'paper.exit.settled.v1')
      .executeTakeFirstOrThrow();

    expect(response).toMatchObject({ positionId, decision: 'EXIT', settlementId: settlement.id });
    expect(position).toMatchObject({
      state: 'closed',
      exit_proceeds_native: '0.085',
      realized_pnl_native: '-0.015',
    });
    expect(lifecycle).toMatchObject({ lifecycle_version: 1, state: 'terminal' });
    expect(fill).toMatchObject({
      cost_basis_native: '0.1',
      proceeds_native: '0.085',
      realized_pnl_native: '-0.015',
      fill_model: 'observed_market_cap_ratio_v1',
    });
    expect(await financials.accountBalance(database, wallet.id, 'available')).toBe('4.985');
    expect(await financials.accountBalance(database, wallet.id, 'invested')).toBe('0');
    expect(await financials.realizedPnlBalance(database, wallet.id)).toBe('-0.015');
    expect(await unbalancedLedgerTransactions()).toBe(0);
    expect(event.envelope).toMatchObject({
      payload: {
        decision: 'EXIT',
        settlement: {
          cost_basis_native: '0.1',
          proceeds_native: '0.085',
          realized_pnl_native: '-0.015',
        },
      },
    });
  });

  it('settles a protected profitable EXIT with the correct realized-PnL ledger direction', async () => {
    const positionId = await openPosition('206');
    const wallet = await database.selectFrom('paper_wallets').select('id').executeTakeFirstOrThrow();

    const protectedHold = await lifecycleHandler().execute(observation(positionId, 1, '24000'));
    const exit = await lifecycleHandler().execute(observation(positionId, 2, '23000'));
    const settlement = await database.selectFrom('paper_exit_settlements').selectAll().executeTakeFirstOrThrow();

    expect(protectedHold).toMatchObject({ decision: 'HOLD', settlementId: null });
    expect(exit).toMatchObject({ decision: 'EXIT', settlementId: settlement.id });
    expect(settlement).toMatchObject({
      cost_basis_native: '0.1',
      proceeds_native: '0.1916666666666666666',
      realized_pnl_native: '0.0916666666666666666',
    });
    expect(await financials.accountBalance(database, wallet.id, 'available'))
      .toBe('5.0916666666666666666');
    expect(await financials.accountBalance(database, wallet.id, 'invested')).toBe('0');
    expect(await financials.realizedPnlBalance(database, wallet.id))
      .toBe('0.0916666666666666666');
    expect(await unbalancedLedgerTransactions()).toBe(0);
  });

  it('replays exactly and rejects conflicts, skipped sequences, and observations after closure', async () => {
    const positionId = await openPosition('203');
    const command = observation(positionId, 1, '13000');
    const first = await lifecycleHandler().execute(command);
    const replay = await lifecycleHandler().execute({ ...command, authJti: 'paper-observation-replay-jti' });

    expect(replay).toEqual({ ...first, duplicate: true });
    await expect(lifecycleHandler().execute({
      ...command,
      idempotencyKey: 'paper-observation-conflict',
      authJti: 'paper-observation-conflict-jti',
      body: { ...command.body, market: { ...command.body.market, market_cap_usd: '14000' } },
    })).rejects.toMatchObject({ code: 'PAPER_OBSERVATION_CONFLICT' });
    await expect(lifecycleHandler().execute(observation(positionId, 3, '14000')))
      .rejects.toMatchObject({ code: 'PAPER_OBSERVATION_OUT_OF_ORDER' });

    await lifecycleHandler().execute(observation(positionId, 2, '10000'));
    await expect(lifecycleHandler().execute(observation(positionId, 3, '9000')))
      .rejects.toMatchObject({ code: 'PAPER_FINANCIAL_POSITION_CLOSED' });
    expect(await count('paper_exit_settlements')).toBe(1);
  });

  it('serializes concurrent duplicate EXIT without double wallet credit', async () => {
    const positionId = await openPosition('204');
    const command = observation(positionId, 1, '10200');
    const results = await Promise.all([
      lifecycleHandler().execute(command),
      lifecycleHandler().execute({ ...command, authJti: 'paper-exit-concurrent-jti' }),
    ]);
    const wallet = await database.selectFrom('paper_wallets').select('id').executeTakeFirstOrThrow();

    expect(new Set(results.map((result) => result.settlementId)).size).toBe(1);
    expect(await count('paper_exit_settlements')).toBe(1);
    expect(await financials.accountBalance(database, wallet.id, 'available')).toBe('4.985');
    expect(await financials.realizedPnlBalance(database, wallet.id)).toBe('-0.015');
  });

  it('rejects stale observations and rolls back settlement when outbox creation fails', async () => {
    const positionId = await openPosition('205');
    const stale = observation(positionId, 1, '10200');
    stale.body.market.observed_at = new Date(testClock.getTime() - 600_000).toISOString();

    await expect(lifecycleHandler().execute(stale)).rejects.toMatchObject({ code: 'PAPER_OBSERVATION_INVALID' });
    expect(await count('paper_position_lifecycle_decisions')).toBe(0);
    expect(await count('paper_exit_settlements')).toBe(0);

    class FailingOutbox extends OutboxRepository {
      public override enqueue(): Promise<void> {
        return Promise.reject(new Error('forced settlement outbox failure'));
      }
    }

    const failing = lifecycleHandler(new FailingOutbox());
    await expect(failing.execute(observation(positionId, 1, '10200')))
      .rejects.toThrow('forced settlement outbox failure');
    expect(await count('paper_position_lifecycle_decisions')).toBe(0);
    expect(await count('paper_exit_settlements')).toBe(0);
    expect((await database.selectFrom('paper_positions').select('state').executeTakeFirstOrThrow()).state).toBe('open');
  });

  function lifecycleHandler(events: OutboxRepository = outbox): ObservePaperFinancialPositionCommandHandler {
    return new ObservePaperFinancialPositionCommandHandler(
      database,
      commandInbox,
      new PaperPositionLifecycleRepository(),
      financials,
      events,
      { enabled: true, observationMaxAgeSeconds: 120 },
      undefined,
      () => testClock,
    );
  }

  async function openPosition(sourceId: string): Promise<string> {
    const recorded = await recordHandler.execute({
      idempotencyKey: `opportunity-${sourceId}`,
      authJti: `opportunity-jti-${sourceId}`,
      subject: 'laravel-service',
      correlationId: `correlation-${sourceId}`,
      traceparent,
      body: opportunity(sourceId),
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
      .select(['id', 'result_sha256', 'created_at'])
      .where('opportunity_id', '=', recorded.opportunityId)
      .executeTakeFirstOrThrow();
    testClock = new Date(evaluation.created_at.getTime());
    const entry = new ExecutePaperEntryCommandHandler(
      database,
      commandInbox,
      new PaperEntryRepository(),
      new PaperPositionMonitoringRepository(),
      outbox,
      {
        enabled: true,
        openingBalanceNative: '5',
        entryNotionalNative: '0.1',
        intentMaxAgeSeconds: 300,
      },
      undefined,
      () => testClock,
    );
    const result = await entry.execute(entryCommand({
      opportunityId: recorded.opportunityId,
      sourceOpportunityId: sourceId,
      evaluationId: evaluation.id,
      resultSha256: evaluation.result_sha256,
    }));

    return result.positionId;
  }

  async function count(table: keyof Database): Promise<number> {
    const result = await database.selectFrom(table)
      .select(sql.raw<number>('count(*)::int').as('count'))
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

function observation(positionId: string, sequence: number, marketCap: string): ObservePaperFinancialPositionCommand {
  return {
    idempotencyKey: `paper-financial-observation-${sequence}`,
    authJti: `paper-financial-observation-jti-${sequence}`,
    subject: 'laravel-service',
    correlationId: `paper-financial-observation-correlation-${sequence}`,
    traceparent,
    body: {
      schema_version: 1,
      position_id: positionId,
      source: {
        system: 'meme-scanner-laravel',
        observation_id: `engine-position-${positionId}-observation-${sequence}`,
        sequence,
      },
      subject: { control_plane_user_id: '7' },
      network: { id: SOLANA_MAINNET_ID },
      asset: { address: assetAddress },
      market: {
        market_cap_usd: marketCap,
        price_usd: '0.00000085',
        liquidity_usd: '2800',
        observed_at: testClock.toISOString(),
        fetched_at: testClock.toISOString(),
        provider: 'birdeye',
      },
      validation: { status: 'eligible', identity_verified: true, simulation_allowed: true },
    },
  };
}

function entryCommand(source: {
  readonly opportunityId: string;
  readonly sourceOpportunityId: string;
  readonly evaluationId: string;
  readonly resultSha256: string;
}): ExecutePaperEntryCommand {
  return {
    idempotencyKey: `paper-entry-${source.sourceOpportunityId}`,
    authJti: `paper-entry-jti-${source.sourceOpportunityId}`,
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
      asset: { address: assetAddress, symbol: 'MEME' },
      entry: {
        requested_notional_native: '0.1',
        market_cap_usd: '12000',
        price_usd: '0.000001',
        liquidity_usd: '3000.25',
        intent_created_at: testClock.toISOString(),
        expires_at: new Date(testClock.getTime() + 300_000).toISOString(),
      },
      authority: {
        execution_mode: 'paper', entry_mode: 'auto', trading_enabled: true,
        preference_version: 'preference-1', kill_switch_engaged: false,
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

function opportunity(sourceId: string): OpportunityCommand {
  return {
    schema_version: 1,
    source: {
      system: 'meme-scanner-laravel', opportunity_id: sourceId,
      discovery_key: sourceId.padStart(64, 'a'), scanner: 'new-token',
    },
    subject: { control_plane_user_id: '7' },
    network: { id: SOLANA_MAINNET_ID },
    asset: { address: assetAddress, symbol: 'MEME' },
    market_snapshot: {
      price_usd: { value: '0.000001', provider: 'birdeye' },
      market_cap_usd: { value: '12000', provider: 'birdeye' },
      liquidity_usd: { value: '3000.25', provider: 'birdeye' },
      volume_usd: { value: '900.5', provider: 'birdeye', window: '1m' },
    },
    qualification: {
      qualified_at: '2026-10-07T11:59:00.000Z', discovery_market_cap_usd: '10000',
      move_since_discovery_percent: '20', classification: 'strong',
    },
    security: { status: 'passed', provider: 'goplus', passed: true, score: 95, risks: [] },
  };
}
