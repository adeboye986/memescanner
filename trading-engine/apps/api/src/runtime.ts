import type { Logger } from 'pino';

import { AcceptNoopCommandHandler } from '../../../src/application/handlers/accept-noop-command-handler.js';
import { ExecutePaperEntryCommandHandler } from '../../../src/application/handlers/execute-paper-entry-command-handler.js';
import { ObservePaperPositionCommandHandler } from '../../../src/application/handlers/observe-paper-position-command-handler.js';
import { RecordOpportunityCommandHandler } from '../../../src/application/handlers/record-opportunity-command-handler.js';
import { RecordPaperPositionCommandHandler } from '../../../src/application/handlers/record-paper-position-command-handler.js';
import type { EngineConfig } from '../../../src/config/env.js';
import { createDatabase } from '../../../src/infrastructure/database/client.js';
import { migrateDatabase } from '../../../src/infrastructure/database/migrate.js';
import { CommandInboxRepository } from '../../../src/infrastructure/database/repositories/command-inbox-repository.js';
import { OpportunityRepository } from '../../../src/infrastructure/database/repositories/opportunity-repository.js';
import { PaperEntryRepository } from '../../../src/infrastructure/database/repositories/paper-entry-repository.js';
import { PaperPositionLifecycleRepository } from '../../../src/infrastructure/database/repositories/paper-position-lifecycle-repository.js';
import { OutboxRepository } from '../../../src/infrastructure/database/repositories/outbox-repository.js';
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

export interface StartApiDependencies {
  readonly databaseFactory?: typeof createDatabase;
  readonly appBuilder?: typeof buildApp;
}

export async function startApi(
  options: StartApiOptions,
  dependencies: StartApiDependencies = {},
): Promise<RuntimeHandle> {
  const databaseFactory = dependencies.databaseFactory ?? createDatabase;
  const appBuilder = dependencies.appBuilder ?? buildApp;
  const database = databaseFactory(options.config);
  const commandInbox = new CommandInboxRepository();
  const outbox = new OutboxRepository();
  const opportunities = new OpportunityRepository();
  const paperEntries = new PaperEntryRepository();
  const paperPositions = new PaperPositionLifecycleRepository();
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
  const paperEntryHandler = new ExecutePaperEntryCommandHandler(
    database,
    commandInbox,
    paperEntries,
    outbox,
    {
      enabled: options.config.paperEntryEnabled,
      openingBalanceNative: options.config.paperOpeningBalanceNative,
      entryNotionalNative: options.config.paperEntryNotionalNative,
      intentMaxAgeSeconds: options.config.paperEntryIntentMaxAgeSeconds,
    },
  );
  const paperPositionRegistrationHandler = new RecordPaperPositionCommandHandler(
    database,
    commandInbox,
    paperPositions,
    outbox,
  );
  const paperPositionObservationHandler = new ObservePaperPositionCommandHandler(
    database,
    commandInbox,
    paperPositions,
    outbox,
  );
  const app = appBuilder({
    config: options.config,
    database,
    noopHandler,
    opportunityHandler,
    paperEntryHandler,
    paperPositionRegistrationHandler,
    paperPositionObservationHandler,
    logger: options.logger,
  });
  const close = idempotentClose(async (): Promise<void> => {
    await closeInOrder('api resource shutdown', [
      { name: 'fastify', close: (): Promise<void> => app.close() },
      { name: 'database', close: (): Promise<void> => database.destroy() },
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
        { cause: cleanupError },
      );
    }

    throw error;
  }

  return { close };
}
