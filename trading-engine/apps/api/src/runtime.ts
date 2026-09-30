import type { Logger } from 'pino';

import { AcceptNoopCommandHandler } from '../../../src/application/handlers/accept-noop-command-handler.js';
import { RecordOpportunityCommandHandler } from '../../../src/application/handlers/record-opportunity-command-handler.js';
import type { EngineConfig } from '../../../src/config/env.js';
import { createDatabase } from '../../../src/infrastructure/database/client.js';
import { migrateDatabase } from '../../../src/infrastructure/database/migrate.js';
import { CommandInboxRepository } from '../../../src/infrastructure/database/repositories/command-inbox-repository.js';
import { OpportunityRepository } from '../../../src/infrastructure/database/repositories/opportunity-repository.js';
import { OutboxRepository } from '../../../src/infrastructure/database/repositories/outbox-repository.js';
import {
  closeRedis,
  createRedisConnection,
} from '../../../src/infrastructure/queue/connection.js';
import {
  closeInOrder,
  idempotentClose,
  type RuntimeHandle,
} from '../../../src/infrastructure/runtime/process-lifecycle.js';
import { buildApp } from './app.js';

export interface StartApiOptions {
  readonly config: EngineConfig;
  readonly logger: Logger;
  readonly runMigrations?: boolean;
}

export async function startApi(
  options: StartApiOptions,
): Promise<RuntimeHandle> {
  const database = createDatabase(options.config);
  const redis = createRedisConnection(options.config);
  const commandInbox = new CommandInboxRepository();
  const outbox = new OutboxRepository();
  const opportunities = new OpportunityRepository();
  const noopHandler = new AcceptNoopCommandHandler(
    database,
    commandInbox,
    outbox,
  );
  const opportunityHandler = new RecordOpportunityCommandHandler(
    database,
    commandInbox,
    opportunities,
    outbox,
  );
  const app = buildApp({
    config: options.config,
    database,
    redis,
    noopHandler,
    opportunityHandler,
    logger: options.logger,
  });
  const close = idempotentClose(async (): Promise<void> => {
    await closeInOrder('api resource shutdown', [
      { name: 'fastify', close: () => app.close() },
      { name: 'redis', close: () => closeRedis(redis) },
      { name: 'database', close: () => database.destroy() },
    ]);
  });

  try {
    if (options.runMigrations !== false) {
      await migrateDatabase(database);
    }

    await app.listen({
      host: options.config.host,
      port: options.config.port,
    });
  } catch (error) {
    try {
      await close();
    } catch (cleanupError) {
      throw new AggregateError(
        [error, cleanupError],
        'API startup and cleanup failed',
      );
    }

    throw error;
  }

  return { close };
}
