import pino, { type Logger } from 'pino';
import type { Kysely } from 'kysely';
import {
  afterAll,
  beforeAll,
  beforeEach,
  describe,
  expect,
  it,
} from 'vitest';

import { AcceptNoopCommandHandler } from '../../src/application/handlers/accept-noop-command-handler.js';
import type { EngineConfig } from '../../src/config/env.js';
import { createDatabase, type Database } from '../../src/infrastructure/database/client.js';
import { CommandInboxRepository } from '../../src/infrastructure/database/repositories/command-inbox-repository.js';
import {
  OutboxRepository,
  type OutboxRecord,
} from '../../src/infrastructure/database/repositories/outbox-repository.js';
import { LaravelWebhookClient } from '../../src/infrastructure/http/laravel-webhook-client.js';
import { OutboxDispatcher } from '../../src/workers/outbox-dispatcher.js';
import { FakeLaravelReceiver } from '../support/fake-laravel-receiver.js';
import {
  createTestIdentity,
  resetDatabase,
  startTestEnvironment,
  stopTestEnvironment,
  type TestEnvironment,
} from '../support/test-environment.js';

describe('transactional outbox delivery', () => {
  let environment: TestEnvironment;
  let database: Kysely<Database>;
  let config: EngineConfig;
  let handler: AcceptNoopCommandHandler;
  let outbox: OutboxRepository;
  let logger: Logger;

  beforeAll(async () => {
    environment = await startTestEnvironment();
    config = createTestIdentity(environment).config;
    database = createDatabase(config);
    outbox = new OutboxRepository();
    handler = new AcceptNoopCommandHandler(
      database,
      new CommandInboxRepository(),
      outbox,
    );
    logger = pino({ level: 'silent' });
  });

  beforeEach(async () => {
    await resetDatabase(database);
  });

  afterAll(async () => {
    await database.destroy();
    await stopTestEnvironment(environment);
  });

  it('retries a failed webhook and publishes one logical event', async () => {
    const receiver = new FakeLaravelReceiver(config.laravelWebhookHmacSecret);
    receiver.failNext(1);
    const webhookUrl = await receiver.start();
    const dispatcher = createDispatcher(webhookUrl);
    await createSyntheticEvent('retry-event');

    const first = await dispatcher.dispatchBatch();
    const failedEvent = await database
      .selectFrom('event_outbox')
      .selectAll()
      .executeTakeFirstOrThrow();
    await database
      .updateTable('event_outbox')
      .set({ available_at: new Date(0) })
      .where('id', '=', failedEvent.id)
      .execute();
    const second = await dispatcher.dispatchBatch();
    const publishedEvent = await database
      .selectFrom('event_outbox')
      .selectAll()
      .executeTakeFirstOrThrow();
    const attempts = await database
      .selectFrom('event_delivery_attempts')
      .selectAll()
      .orderBy('attempt_number')
      .execute();

    expect(first).toEqual({ claimed: 1, published: 0, failed: 1 });
    expect(second).toEqual({ claimed: 1, published: 1, failed: 0 });
    expect(publishedEvent.status).toBe('published');
    expect(publishedEvent.attempt_count).toBe(2);
    expect(attempts.map((attempt) => attempt.outcome)).toEqual([
      'failed',
      'published',
    ]);
    expect(receiver.requests).toHaveLength(1);
    await receiver.stop();
  });

  it('lets the fake Laravel inbox acknowledge duplicate delivery as a no-op', async () => {
    const receiver = new FakeLaravelReceiver(config.laravelWebhookHmacSecret);
    const webhookUrl = await receiver.start();
    const client = new LaravelWebhookClient({
      ...config,
      laravelWebhookUrl: webhookUrl,
    });
    await createSyntheticEvent('duplicate-event');
    const event = await database
      .selectFrom('event_outbox')
      .selectAll()
      .executeTakeFirstOrThrow();

    const first = await client.deliver(event.envelope);
    const second = await client.deliver(event.envelope);

    expect(first.accepted).toBe(true);
    expect(second.accepted).toBe(true);
    expect(receiver.requests).toHaveLength(2);
    expect(receiver.requests[0]?.duplicate).toBe(false);
    expect(receiver.requests[1]?.duplicate).toBe(true);
    expect(receiver.requests[0]?.headers['x-correlation-id']).toBe(
      event.correlation_id,
    );
    expect(receiver.requests[0]?.headers['traceparent']).toBe(event.traceparent);
    await receiver.stop();
  });

  it('recovers an expired claim after a simulated worker crash', async () => {
    const receiver = new FakeLaravelReceiver(config.laravelWebhookHmacSecret);
    const webhookUrl = await receiver.start();
    const dispatcher = createDispatcher(webhookUrl);
    await createSyntheticEvent('restart-event');
    const claimed = await outbox.claimBatch(database, 'crashed-worker', 1, 1_000);
    const staleTime = new Date(Date.now() - 5_000);
    await database
      .updateTable('event_outbox')
      .set({ claimed_at: staleTime })
      .where('id', '=', claimed[0]?.id ?? '')
      .execute();

    const result = await dispatcher.dispatchBatch();
    const event = await database
      .selectFrom('event_outbox')
      .selectAll()
      .executeTakeFirstOrThrow();

    expect(claimed).toHaveLength(1);
    expect(result).toEqual({ claimed: 1, published: 1, failed: 0 });
    expect(event.status).toBe('published');
    expect(receiver.requests).toHaveLength(1);
    await receiver.stop();
  });

  function createDispatcher(webhookUrl: URL): OutboxDispatcher {
    const deliveryConfig: EngineConfig = {
      ...config,
      laravelWebhookUrl: webhookUrl,
    };

    return new OutboxDispatcher(
      deliveryConfig,
      database,
      outbox,
      new LaravelWebhookClient(deliveryConfig),
      logger,
    );
  }

  async function createSyntheticEvent(suffix: string): Promise<OutboxRecord> {
    await handler.execute({
      idempotencyKey: `noop-${suffix}`,
      authJti: `auth-${suffix}`,
      subject: 'laravel-service',
      correlationId: `correlation-${suffix}`,
      traceparent: '00-11111111111111111111111111111111-2222222222222222-01',
    });

    return database
      .selectFrom('event_outbox')
      .selectAll()
      .executeTakeFirstOrThrow();
  }
});
