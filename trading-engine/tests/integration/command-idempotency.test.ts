import type { Redis } from 'ioredis';
import { sql, type Kysely } from 'kysely';
import {
  afterAll,
  beforeAll,
  beforeEach,
  describe,
  expect,
  it,
} from 'vitest';

import { buildApp } from '../../apps/api/src/app.js';
import { AcceptNoopCommandHandler } from '../../src/application/handlers/accept-noop-command-handler.js';
import { RecordOpportunityCommandHandler } from '../../src/application/handlers/record-opportunity-command-handler.js';
import { createDatabase, type Database } from '../../src/infrastructure/database/client.js';
import { CommandInboxRepository } from '../../src/infrastructure/database/repositories/command-inbox-repository.js';
import { OpportunityRepository } from '../../src/infrastructure/database/repositories/opportunity-repository.js';
import { OutboxRepository } from '../../src/infrastructure/database/repositories/outbox-repository.js';
import {
  closeRedis,
  createRedisConnection,
} from '../../src/infrastructure/queue/connection.js';
import {
  createServiceToken,
  createTestIdentity,
  resetDatabase,
  resetRedis,
  startTestEnvironment,
  stopTestEnvironment,
  type TestEnvironment,
  type TestIdentity,
} from '../support/test-environment.js';

describe('idempotent no-op command', () => {
  let environment: TestEnvironment;
  let identity: TestIdentity;
  let database: Kysely<Database>;
  let redis: Redis;
  let handler: AcceptNoopCommandHandler;
  let app: Awaited<ReturnType<typeof buildApp>>;

  beforeAll(async () => {
    environment = await startTestEnvironment();
    identity = createTestIdentity(environment);
    database = createDatabase(identity.config);
    redis = createRedisConnection(identity.config);
    handler = new AcceptNoopCommandHandler(
      database,
      new CommandInboxRepository(),
      new OutboxRepository(),
    );
    app = buildApp({
      config: identity.config,
      database,
      redis,
      noopHandler: handler,
      opportunityHandler: new RecordOpportunityCommandHandler(
        database,
        new CommandInboxRepository(),
        new OpportunityRepository(),
        new OutboxRepository(),
      ),
    });
  });

  beforeEach(async () => {
    await resetDatabase(database);
    await resetRedis(environment.redisUrl);
  });

  afterAll(async () => {
    await app.close();
    await closeRedis(redis);
    await database.destroy();
    await stopTestEnvironment(environment);
  });

  it('persists one command and one event under concurrent duplicate requests', async () => {
    const command = {
      idempotencyKey: 'concurrent-noop-1',
      authJti: 'auth-jti-concurrent-1',
      subject: 'laravel-service',
      correlationId: 'correlation-concurrent-1',
      traceparent: '00-11111111111111111111111111111111-2222222222222222-01',
      message: 'synthetic',
    } as const;

    const results = await Promise.all([
      handler.execute(command),
      handler.execute(command),
      handler.execute(command),
    ]);
    const commandCount = await database
      .selectFrom('command_inbox')
      .select(sql.raw<number>('count(*)::int').as('count'))
      .executeTakeFirstOrThrow();
    const eventCount = await database
      .selectFrom('event_outbox')
      .select(sql.raw<number>('count(*)::int').as('count'))
      .executeTakeFirstOrThrow();

    expect(new Set(results.map((result) => result.operationId)).size).toBe(1);
    expect(new Set(results.map((result) => result.eventId)).size).toBe(1);
    expect(results.filter((result) => result.duplicate)).toHaveLength(2);
    expect(commandCount.count).toBe(1);
    expect(eventCount.count).toBe(1);
  });

  it('returns 409 when one assertion jti authorizes a different command', async () => {
    const base = {
      authJti: 'one-use-auth-jti',
      subject: 'laravel-service',
      correlationId: 'correlation-replay',
      traceparent: '00-11111111111111111111111111111111-2222222222222222-01',
    } as const;
    await handler.execute({
      ...base,
      idempotencyKey: 'noop-original',
    });

    const second = handler.execute({
      ...base,
      idempotencyKey: 'noop-replayed',
    });

    await expect(second).rejects.toMatchObject({
      code: 'AUTH_REPLAYED',
      statusCode: 409,
      retryable: false,
    });
    const eventCount = await database
      .selectFrom('event_outbox')
      .select(sql.raw<number>('count(*)::int').as('count'))
      .executeTakeFirstOrThrow();
    expect(eventCount.count).toBe(1);
  });

  it('authenticates the HTTP command and propagates correlation and trace context', async () => {
    const token = await createServiceToken(identity, {
      scopes: ['commands:noop'],
      jti: 'http-auth-jti-1',
    });
    const traceparent = '00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01';

    const response = await app.inject({
      method: 'POST',
      url: '/v1/commands/noop',
      headers: {
        authorization: `Bearer ${token}`,
        'idempotency-key': 'http-noop-1',
        'x-correlation-id': 'http-correlation-1',
        traceparent,
      },
      payload: {
        message: 'contract event',
      },
    });
    const event = await database
      .selectFrom('event_outbox')
      .selectAll()
      .executeTakeFirstOrThrow();

    expect(response.statusCode).toBe(202);
    expect(response.headers['x-correlation-id']).toBe('http-correlation-1');
    expect(response.headers['traceparent']).toBe(traceparent);
    expect(response.json()).toMatchObject({
      status: 'accepted',
      duplicate: false,
    });
    expect(event.correlation_id).toBe('http-correlation-1');
    expect(event.traceparent).toBe(traceparent);
    expect(event.envelope.traceparent).toBe(traceparent);
  });

  it('reports authenticated PostgreSQL and Redis readiness', async () => {
    const token = await createServiceToken(identity, {
      scopes: ['health:read'],
      jti: 'http-health-jti-1',
    });

    const response = await app.inject({
      method: 'GET',
      url: '/v1/health/ready',
      headers: {
        authorization: 'Bearer ' + token,
      },
    });

    expect(response.statusCode).toBe(200);
    expect(response.json()).toMatchObject({
      status: 'ok',
      dependencies: {
        postgres: 'up',
        redis: 'up',
      },
    });
  });

  it('rejects unexpected command fields without writing state', async () => {
    const token = await createServiceToken(identity, {
      scopes: ['commands:noop'],
      jti: 'http-auth-jti-invalid-payload',
    });

    const response = await app.inject({
      method: 'POST',
      url: '/v1/commands/noop',
      headers: {
        authorization: `Bearer ${token}`,
        'idempotency-key': 'http-invalid-payload',
      },
      payload: {
        unexpected: 'must not be accepted',
      },
    });
    const commandCount = await database
      .selectFrom('command_inbox')
      .select(sql.raw<number>('count(*)::int').as('count'))
      .executeTakeFirstOrThrow();

    expect(response.statusCode).toBe(400);
    expect(response.json()).toMatchObject({
      error: {
        code: 'VALIDATION_FAILED',
      },
    });
    expect(commandCount.count).toBe(0);
  });
});
