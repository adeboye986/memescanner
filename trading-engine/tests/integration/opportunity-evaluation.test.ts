import { Value } from '@sinclair/typebox/value';
import type { Kysely } from 'kysely';
import pino, { type Logger } from 'pino';
import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest';

import type { RecordOpportunityCommand } from '../../src/application/commands/record-opportunity-command.js';
import { RecordOpportunityCommandHandler } from '../../src/application/handlers/record-opportunity-command-handler.js';
import { OpportunityEvaluatedPayloadSchema } from '../../src/contracts/events/opportunity-evaluated-event.schema.js';
import {
  ETHEREUM_MAINNET_ID,
  type OpportunityCommand,
} from '../../src/contracts/http/opportunity-command.schema.js';
import type { EngineConfig } from '../../src/config/env.js';
import type { OpportunityEvaluationResult } from '../../src/domain/opportunities/evaluate-opportunity.js';
import {
  OPPORTUNITY_EVALUATION_POLICY_V1,
  OPPORTUNITY_EVALUATION_POLICY_V1_SHA256,
} from '../../src/domain/opportunities/evaluation-policy.js';
import { createDatabase, type Database } from '../../src/infrastructure/database/client.js';
import { CommandInboxRepository } from '../../src/infrastructure/database/repositories/command-inbox-repository.js';
import { OpportunityEvaluationRepository } from '../../src/infrastructure/database/repositories/opportunity-evaluation-repository.js';
import { OpportunityRepository } from '../../src/infrastructure/database/repositories/opportunity-repository.js';
import { OutboxRepository } from '../../src/infrastructure/database/repositories/outbox-repository.js';
import { OpportunityEvaluationDispatcher } from '../../src/workers/opportunity-evaluation-dispatcher.js';
import {
  createTestIdentity,
  resetDatabase,
  startTestEnvironment,
  stopTestEnvironment,
  type TestEnvironment,
} from '../support/test-environment.js';

const traceparent = '00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01';

describe('durable opportunity snapshot evaluation workflow', () => {
  let environment: TestEnvironment;
  let config: EngineConfig;
  let database: Kysely<Database>;
  let logger: Logger;
  let recordHandler: RecordOpportunityCommandHandler;
  let evaluations: OpportunityEvaluationRepository;
  let outbox: OutboxRepository;

  beforeAll(async () => {
    environment = await startTestEnvironment();
    config = createTestIdentity(environment).config;
    database = createDatabase(config);
    logger = pino({ level: 'silent' });
    evaluations = new OpportunityEvaluationRepository();
    outbox = new OutboxRepository();
    recordHandler = new RecordOpportunityCommandHandler(
      database,
      new CommandInboxRepository(),
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

  it('keeps the recorded opportunity durable when evaluation fails and retries successfully', async () => {
    const recorded = await record('failure-retry');
    const before = await opportunityState(recorded.opportunityId);
    const failing = dispatcher((): OpportunityEvaluationResult => {
      throw new Error('forced evaluation failure');
    });

    const first = await failing.dispatchBatch();
    const failedTask = await database
      .selectFrom('opportunity_evaluation_tasks')
      .selectAll()
      .executeTakeFirstOrThrow();

    expect(first).toEqual({
      discovered: 1,
      claimed: 1,
      evaluated: 0,
      duplicates: 0,
      failed: 1,
    });
    expect(failedTask).toMatchObject({
      status: 'pending',
      attempt_count: 1,
      last_error_code: 'EVALUATION_FAILED',
    });
    expect(await opportunityState(recorded.opportunityId)).toEqual(before);
    expect(await count('opportunities')).toBe(1);
    expect(await count('opportunity_evaluations')).toBe(0);
    expect(await count('event_outbox')).toBe(1);

    await database
      .updateTable('opportunity_evaluation_tasks')
      .set({ available_at: new Date(0) })
      .execute();

    const second = await dispatcher().dispatchBatch();
    const third = await dispatcher().dispatchBatch();

    expect(second).toMatchObject({ claimed: 1, evaluated: 1, failed: 0 });
    expect(third).toMatchObject({ claimed: 0, evaluated: 0, duplicates: 0, failed: 0 });
    expect(await count('opportunity_evaluations')).toBe(1);
    expect(await countEvaluatedEvents()).toBe(1);
    expect(await opportunityState(recorded.opportunityId)).toEqual(before);
  });

  it('persists the complete policy snapshot and emits one exact informational event', async () => {
    const recorded = await record('exact-event');

    await dispatcher().dispatchBatch();

    const evaluation = await database
      .selectFrom('opportunity_evaluations')
      .selectAll()
      .executeTakeFirstOrThrow();
    const event = await database
      .selectFrom('event_outbox')
      .selectAll()
      .where('event_type', '=', 'opportunity.evaluated.v1')
      .executeTakeFirstOrThrow();

    expect(evaluation).toMatchObject({
      opportunity_id: recorded.opportunityId,
      policy_key: 'migration-opportunity-snapshot',
      policy_version: 1,
      policy_snapshot: OPPORTUNITY_EVALUATION_POLICY_V1,
      policy_definition_sha256: OPPORTUNITY_EVALUATION_POLICY_V1_SHA256,
      outcome: 'passed',
      reason_codes: [],
      advisory_codes: ['SECURITY_EVIDENCE_UNAVAILABLE'],
      correlation_id: 'evaluation-correlation',
      traceparent,
    });
    expect(event.envelope).toMatchObject({
      event_type: 'opportunity.evaluated.v1',
      schema_version: 1,
      aggregate_type: 'opportunity_evaluation',
      aggregate_id: evaluation.id,
      aggregate_version: 1,
      correlation_id: 'evaluation-correlation',
      causation_id: recorded.eventId,
      traceparent,
      payload: {
        evaluation_id: evaluation.id,
        opportunity_id: recorded.opportunityId,
        policy: {
          key: 'migration-opportunity-snapshot',
          version: 1,
          algorithm_key: 'threshold-matrix',
          algorithm_version: 1,
          definition_sha256: OPPORTUNITY_EVALUATION_POLICY_V1_SHA256,
        },
        source: {
          request_sha256: evaluation.source_request_sha256,
          evaluation_input_sha256: evaluation.evaluation_input_sha256,
        },
        outcome: 'passed',
        reason_codes: [],
        advisory_codes: ['SECURITY_EVIDENCE_UNAVAILABLE'],
        result_sha256: evaluation.result_sha256,
      },
    });
    expect(Value.Check(
      OpportunityEvaluatedPayloadSchema,
      event.envelope.payload,
    )).toBe(true);
    expect(JSON.stringify(event.envelope.payload)).not.toMatch(
      /execution_mode|entry_mode|buy_instruction|approval|authorization|wallet|balance|order|position|signing|transaction_request/i,
    );
  });

  it('uses database claims and uniqueness to make concurrent dispatch one logical evaluation', async () => {
    await record('concurrent');

    const summaries = await Promise.all([
      dispatcher().dispatchBatch(),
      dispatcher().dispatchBatch(),
      dispatcher().dispatchBatch(),
    ]);

    expect(summaries.reduce((total, summary) => total + summary.evaluated, 0)).toBe(1);
    expect(await count('opportunity_evaluations')).toBe(1);
    expect(await countEvaluatedEvents()).toBe(1);
  });

  it('prevents mutation or deletion of frozen policies and completed evaluations', async () => {
    await record('append-only');
    await dispatcher().dispatchBatch();

    await expect(database
      .updateTable('evaluation_policies')
      .set({ algorithm_version: 2 })
      .where('policy_key', '=', OPPORTUNITY_EVALUATION_POLICY_V1.policy_key)
      .execute()).rejects.toThrow(/append-only/);
    await expect(database
      .deleteFrom('opportunity_evaluations')
      .execute()).rejects.toThrow(/append-only/);
    expect(await count('evaluation_policies')).toBe(1);
    expect(await count('opportunity_evaluations')).toBe(1);
  });

  function dispatcher(
    evaluator?: (
      request: OpportunityCommand,
      sourceRequestSha256: string,
    ) => OpportunityEvaluationResult,
  ): OpportunityEvaluationDispatcher {
    return new OpportunityEvaluationDispatcher(
      config,
      database,
      evaluations,
      outbox,
      logger,
      evaluator,
    );
  }

  async function record(suffix: string): Promise<{
    readonly opportunityId: string;
    readonly eventId: string;
  }> {
    return recordHandler.execute(command(suffix));
  }

  async function count(
    table: 'evaluation_policies' | 'event_outbox' | 'opportunities' | 'opportunity_evaluations',
  ): Promise<number> {
    const rows = await database.selectFrom(table).select('created_at').execute();

    return rows.length;
  }

  async function countEvaluatedEvents(): Promise<number> {
    const rows = await database
      .selectFrom('event_outbox')
      .select('id')
      .where('event_type', '=', 'opportunity.evaluated.v1')
      .execute();

    return rows.length;
  }

  async function opportunityState(id: string): Promise<{
    readonly id: string;
    readonly aggregate_version: 1;
    readonly state: 'recorded';
    readonly request_sha256: string;
    readonly accepted_request: Database['opportunities']['accepted_request'];
  }> {
    return database
      .selectFrom('opportunities')
      .select(['id', 'aggregate_version', 'state', 'request_sha256', 'accepted_request'])
      .where('id', '=', id)
      .executeTakeFirstOrThrow();
  }
});

function command(suffix: string): RecordOpportunityCommand {
  const body: OpportunityCommand = {
    schema_version: 1,
    source: {
      system: 'meme-scanner-laravel',
      opportunity_id: suffix,
      discovery_key: hashLike(suffix),
      scanner: 'momentum',
    },
    subject: { control_plane_user_id: '7' },
    network: { id: ETHEREUM_MAINNET_ID },
    asset: { address: '0x1111111111111111111111111111111111111111' },
    market_snapshot: {
      market_cap_usd: { value: '10000', provider: 'dexscreener' },
      liquidity_usd: { value: '2000', provider: 'dexscreener' },
      volume_usd: { value: '500', provider: 'dexscreener', window: '5m' },
      pair: {
        address: '0x2222222222222222222222222222222222222222',
        provider: 'dexscreener',
      },
    },
    qualification: {
      qualified_at: '2026-09-28T12:00:00.000Z',
      move_since_discovery_percent: '35',
    },
    security: {
      status: 'unavailable',
      market_validation: {
        provider: 'dexscreener',
        requested_token_is_base: true,
        pair_available: true,
      },
    },
  };

  return {
    idempotencyKey: `opportunity-${suffix}`,
    authJti: `jti-${suffix}`,
    subject: 'laravel-service',
    correlationId: 'evaluation-correlation',
    traceparent,
    body,
  };
}

function hashLike(value: string): string {
  return Buffer.from(value).toString('hex').padEnd(64, '0').slice(0, 64);
}
