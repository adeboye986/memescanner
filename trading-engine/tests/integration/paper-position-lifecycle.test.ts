import type { Kysely } from 'kysely';
import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest';

import { ObservePaperPositionCommandHandler } from '../../src/application/handlers/observe-paper-position-command-handler.js';
import { RecordOpportunityCommandHandler } from '../../src/application/handlers/record-opportunity-command-handler.js';
import { RecordPaperPositionCommandHandler } from '../../src/application/handlers/record-paper-position-command-handler.js';
import type { PaperPositionObservation, PaperPositionRegistration } from '../../src/contracts/http/paper-position-command.schema.js';
import type { ObservePaperPositionCommand } from '../../src/application/commands/observe-paper-position-command.js';
import type { RecordPaperPositionCommand } from '../../src/application/commands/record-paper-position-command.js';
import { SOLANA_MAINNET_ID, type OpportunityCommand } from '../../src/contracts/http/opportunity-command.schema.js';
import { createDatabase, type Database } from '../../src/infrastructure/database/client.js';
import { CommandInboxRepository } from '../../src/infrastructure/database/repositories/command-inbox-repository.js';
import { OpportunityRepository } from '../../src/infrastructure/database/repositories/opportunity-repository.js';
import { OutboxRepository } from '../../src/infrastructure/database/repositories/outbox-repository.js';
import { PaperPositionLifecycleRepository } from '../../src/infrastructure/database/repositories/paper-position-lifecycle-repository.js';
import {
  createTestIdentity,
  resetDatabase,
  startTestEnvironment,
  stopTestEnvironment,
  type TestEnvironment,
} from '../support/test-environment.js';

const traceparent = '00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01';
const address = 'So11111111111111111111111111111111111111112';

describe('PAPER position lifecycle commands', () => {
  let environment: TestEnvironment;
  let database: Kysely<Database>;
  let opportunities: RecordOpportunityCommandHandler;
  let registrations: RecordPaperPositionCommandHandler;
  let observations: ObservePaperPositionCommandHandler;
  let engineOpportunityId: string;

  beforeAll(async () => {
    environment = await startTestEnvironment();
    database = createDatabase(createTestIdentity(environment).config);
    const inbox = new CommandInboxRepository();
    const outbox = new OutboxRepository();
    const positions = new PaperPositionLifecycleRepository();
    opportunities = new RecordOpportunityCommandHandler(
      database,
      inbox,
      new OpportunityRepository(),
      outbox,
    );
    registrations = new RecordPaperPositionCommandHandler(database, inbox, positions, outbox);
    observations = new ObservePaperPositionCommandHandler(database, inbox, positions, outbox);
  });

  beforeEach(async () => {
    await resetDatabase(database);
    engineOpportunityId = (await opportunities.execute({
      body: opportunity(),
      idempotencyKey: 'opportunity-lifecycle-test',
      authJti: 'opportunity-jti',
      subject: 'laravel-service',
      correlationId: 'paper-lifecycle-test',
      traceparent,
    })).opportunityId;
  });

  afterAll(async () => {
    await database.destroy();
    await stopTestEnvironment(environment);
  });

  it('registers idempotently with an immutable strategy snapshot', async () => {
    const body = registration();
    const first = await registrations.execute(command(body, 'register-one', 'register-jti-one'));
    const replay = await registrations.execute(command(body, 'register-one', 'register-jti-two'));
    const stored = await database.selectFrom('paper_position_lifecycles').selectAll().executeTakeFirstOrThrow();

    expect(replay).toEqual({ ...first, duplicate: true });
    expect(stored.strategy_snapshot).toEqual(body.strategy);
    expect(await count('paper_position_lifecycles')).toBe(1);
    expect(await count('event_outbox')).toBe(2);
  });

  it('rejects conflicting registration without changing the aggregate', async () => {
    await registrations.execute(command(registration(), 'register-conflict-one', 'register-conflict-jti-one'));
    const changed = registration();
    changed.entry.market_cap_usd = '11000';

    await expect(registrations.execute(command(
      changed,
      'register-conflict-two',
      'register-conflict-jti-two',
    ))).rejects.toMatchObject({ code: 'PAPER_POSITION_REGISTRATION_CONFLICT' });
    expect(await count('paper_position_lifecycles')).toBe(1);
  });

  it('persists HOLD transitions and rejects out-of-order observations', async () => {
    const position = await registrations.execute(command(registration(), 'register-hold', 'register-hold-jti'));
    const body = observation(position.positionId, 1, '21000');
    const result = await observations.execute(observationCommand(body, 'observe-hold', 'observe-hold-jti'));

    expect(result.decision).toBe('HOLD');
    const stored = await database.selectFrom('paper_position_lifecycles').selectAll().executeTakeFirstOrThrow();
    expect(stored.protection_state).toBe('level_1');
    expect(stored.lifecycle_version).toBe(1);

    await expect(observations.execute(observationCommand(
      observation(position.positionId, 3, '22000'),
      'observe-gap',
      'observe-gap-jti',
    ))).rejects.toMatchObject({ code: 'PAPER_OBSERVATION_OUT_OF_ORDER' });
  });

  it('does not duplicate transitions for an idempotent observation replay', async () => {
    const position = await registrations.execute(command(registration(), 'register-replay', 'register-replay-jti'));
    const body = observation(position.positionId, 1, '12000');
    const first = await observations.execute(observationCommand(body, 'observe-replay', 'observe-replay-jti-one'));
    const replay = await observations.execute(observationCommand(body, 'observe-replay', 'observe-replay-jti-two'));

    expect(replay).toEqual({ ...first, duplicate: true });
    expect(await count('paper_position_lifecycle_decisions')).toBe(1);
  });

  it('accepts an eligible market-cap-only observation without inventing price or liquidity', async () => {
    const position = await registrations.execute(command(
      registration(),
      'register-market-cap-only',
      'register-market-cap-only-jti',
    ));
    const body = observation(position.positionId, 1, '12000');
    delete body.market.price_usd;
    delete body.market.liquidity_usd;

    await observations.execute(observationCommand(
      body,
      'observe-market-cap-only',
      'observe-market-cap-only-jti',
    ));

    const decision = await database
      .selectFrom('paper_position_lifecycle_decisions')
      .selectAll()
      .executeTakeFirstOrThrow();
    expect(decision.observed_price_usd).toBeNull();
    expect(decision.observed_liquidity_usd).toBeNull();
  });

  it('serializes concurrent duplicate observations into one lifecycle decision', async () => {
    const position = await registrations.execute(command(
      registration(),
      'register-concurrent-observation',
      'register-concurrent-observation-jti',
    ));
    const body = observation(position.positionId, 1, '12000');
    const results = await Promise.all([
      observations.execute(observationCommand(body, 'observe-concurrent', 'observe-concurrent-jti-one')),
      observations.execute(observationCommand(body, 'observe-concurrent', 'observe-concurrent-jti-two')),
      observations.execute(observationCommand(body, 'observe-concurrent', 'observe-concurrent-jti-three')),
    ]);

    expect(new Set(results.map((result) => result.decisionId)).size).toBe(1);
    expect(results.filter((result) => result.duplicate)).toHaveLength(2);
    expect(await count('paper_position_lifecycle_decisions')).toBe(1);
    expect(await count('event_outbox')).toBe(3);
  });

  it('continues from durable aggregate state after a handler restart', async () => {
    const position = await registrations.execute(command(
      registration(),
      'register-restart',
      'register-restart-jti',
    ));
    await observations.execute(observationCommand(
      observation(position.positionId, 1, '12000'),
      'observe-before-restart',
      'observe-before-restart-jti',
    ));
    const restartedHandler = new ObservePaperPositionCommandHandler(
      database,
      new CommandInboxRepository(),
      new PaperPositionLifecycleRepository(),
      new OutboxRepository(),
    );

    await restartedHandler.execute(observationCommand(
      observation(position.positionId, 2, '13000'),
      'observe-after-restart',
      'observe-after-restart-jti',
    ));

    const lifecycle = await database.selectFrom('paper_position_lifecycles').selectAll().executeTakeFirstOrThrow();
    expect(lifecycle.lifecycle_version).toBe(2);
    expect(lifecycle.last_observation_sequence).toBe(2);
    expect(lifecycle.peak_market_cap_usd).toBe('13000');
    expect(await count('paper_position_lifecycle_decisions')).toBe(2);
  });

  it('rolls back inbox, lifecycle state, decision and outbox when event persistence fails', async () => {
    class FailingOutboxRepository extends OutboxRepository {
      public override enqueue(): Promise<void> {
        return Promise.reject(new Error('forced lifecycle outbox failure'));
      }
    }
    const position = await registrations.execute(command(
      registration(),
      'register-rollback',
      'register-rollback-jti',
    ));
    const failingHandler = new ObservePaperPositionCommandHandler(
      database,
      new CommandInboxRepository(),
      new PaperPositionLifecycleRepository(),
      new FailingOutboxRepository(),
    );

    await expect(failingHandler.execute(observationCommand(
      observation(position.positionId, 1, '12000'),
      'observe-rollback',
      'observe-rollback-jti',
    ))).rejects.toThrow('forced lifecycle outbox failure');

    const lifecycle = await database.selectFrom('paper_position_lifecycles').selectAll().executeTakeFirstOrThrow();
    expect(lifecycle.lifecycle_version).toBe(0);
    expect(lifecycle.last_observation_id).toBeNull();
    expect(await count('paper_position_lifecycle_decisions')).toBe(0);
    expect(await count('event_outbox')).toBe(2);
  });

  it('atomically persists an EXIT and both lifecycle outbox events then becomes terminal', async () => {
    const position = await registrations.execute(command(registration(), 'register-exit', 'register-exit-jti'));
    const result = await observations.execute(observationCommand(
      observation(position.positionId, 1, '8500'),
      'observe-exit',
      'observe-exit-jti',
    ));

    expect(result.decision).toBe('EXIT');
    const lifecycle = await database.selectFrom('paper_position_lifecycles').selectAll().executeTakeFirstOrThrow();
    expect(lifecycle.state).toBe('terminal');
    expect(await count('paper_position_lifecycle_decisions')).toBe(1);
    expect(await count('event_outbox')).toBe(4);
    const emitted = await database
      .selectFrom('event_outbox')
      .select(['event_type', 'envelope'])
      .where('aggregate_id', '=', position.positionId)
      .orderBy('created_at', 'asc')
      .execute();
    expect(emitted.map((event) => event.event_type)).toEqual([
      'paper.position.recorded.v1',
      'paper.position.evaluated.v1',
      'paper.exit.requested.v1',
    ]);
    expect(emitted[2]?.envelope.payload).toMatchObject({
      decision: 'EXIT',
      observed_multiple: '0.85',
      trigger_multiple: '0.9',
    });

    await expect(observations.execute(observationCommand(
      observation(position.positionId, 2, '8000'),
      'observe-terminal',
      'observe-terminal-jti',
    ))).rejects.toMatchObject({ code: 'PAPER_POSITION_TERMINAL' });
  });

  async function count(table: keyof Database): Promise<number> {
    const result = await database
      .selectFrom(table)
      .select(({ fn }) => fn.countAll<string>().as('count'))
      .executeTakeFirstOrThrow();

    return Number(result.count);
  }

  function registration(): PaperPositionRegistration {
    return {
      schema_version: 1,
      source: {
        system: 'meme-scanner-laravel',
        paper_position_id: '94',
        trade_opportunity_id: '106',
        engine_opportunity_id: engineOpportunityId,
      },
      subject: { control_plane_user_id: '2' },
      network: { id: SOLANA_MAINNET_ID },
      asset: { address, symbol: 'CANARY' },
      entry: {
        initial_investment_native: '0.1',
        market_cap_usd: '10000',
        price_usd: '0.001',
        liquidity_usd: '1000',
        entered_at: '2026-10-02T20:00:00.000Z',
      },
      strategy: {
        stop_loss_percent: '10',
        protection_level_1_percent: '100',
        protection_level_2_percent: '200',
      },
    };
  }

  function observation(positionId: string, sequence: number, marketCap: string): PaperPositionObservation {
    return {
      schema_version: 1,
      position_id: positionId,
      source: {
        paper_position_id: '94',
        observation_id: `paper-position-94-observation-${sequence}`,
        sequence,
      },
      subject: { control_plane_user_id: '2' },
      network: { id: SOLANA_MAINNET_ID },
      asset: { address },
      market: {
        market_cap_usd: marketCap,
        price_usd: '0.001',
        liquidity_usd: '1000',
        observed_at: '2026-10-02T20:00:00.000Z',
        fetched_at: '2026-10-02T20:00:00.000Z',
        provider: 'dexscreener',
      },
      validation: {
        status: 'eligible',
        identity_verified: true,
        simulation_allowed: true,
      },
    };
  }
});

function command(body: PaperPositionRegistration, idempotencyKey: string, authJti: string): RecordPaperPositionCommand {
  return { body, idempotencyKey, authJti, subject: 'laravel-service', correlationId: 'paper-lifecycle-test', traceparent };
}

function observationCommand(body: PaperPositionObservation, idempotencyKey: string, authJti: string): ObservePaperPositionCommand {
  return { body, idempotencyKey, authJti, subject: 'laravel-service', correlationId: 'paper-lifecycle-test', traceparent };
}

function opportunity(): OpportunityCommand {
  return {
    schema_version: 1,
    source: {
      system: 'meme-scanner-laravel',
      opportunity_id: '106',
      discovery_key: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
      scanner: 'new-token',
    },
    subject: { control_plane_user_id: '2' },
    network: { id: SOLANA_MAINNET_ID },
    asset: { address, symbol: 'CANARY' },
    market_snapshot: {
      price_usd: { value: '0.001', provider: 'birdeye' },
      market_cap_usd: { value: '10000', provider: 'birdeye' },
      liquidity_usd: { value: '1000', provider: 'birdeye' },
    },
    qualification: {
      qualified_at: '2026-10-02T19:59:00.000Z',
      discovery_market_cap_usd: '9000',
      move_since_discovery_percent: '11.111111',
      classification: 'strong',
    },
    security: {
      status: 'passed',
      provider: 'goplus',
    },
  };
}
