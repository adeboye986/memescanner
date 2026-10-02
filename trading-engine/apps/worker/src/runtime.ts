import type { Logger } from 'pino';

import type { EngineConfig } from '../../../src/config/env.js';
import { createDatabase } from '../../../src/infrastructure/database/client.js';
import { migrateDatabase } from '../../../src/infrastructure/database/migrate.js';
import { OpportunityEvaluationRepository } from '../../../src/infrastructure/database/repositories/opportunity-evaluation-repository.js';
import { OutboxRepository } from '../../../src/infrastructure/database/repositories/outbox-repository.js';
import { LaravelWebhookClient } from '../../../src/infrastructure/http/laravel-webhook-client.js';
import {
  closeInOrder,
  idempotentClose,
  type RuntimeHandle,
} from '../../../src/infrastructure/runtime/process-lifecycle.js';
import { OpportunityEvaluationDispatcher } from '../../../src/workers/opportunity-evaluation-dispatcher.js';
import { OutboxDispatcher } from '../../../src/workers/outbox-dispatcher.js';
import {
  startWorkflowPoller,
  type WorkflowPollerOptions,
} from '../../../src/workers/workflow-poller.js';

export interface StartWorkerOptions {
  readonly config: EngineConfig;
  readonly logger: Logger;
  readonly runMigrations?: boolean;
}

export interface StartWorkerDependencies {
  readonly databaseFactory?: typeof createDatabase;
  readonly pollerStarter?: (options: WorkflowPollerOptions) => RuntimeHandle;
}

export async function startWorker(
  options: StartWorkerOptions,
  dependencies: StartWorkerDependencies = {},
): Promise<RuntimeHandle> {
  const databaseFactory = dependencies.databaseFactory ?? createDatabase;
  const pollerStarter = dependencies.pollerStarter ?? startWorkflowPoller;
  const database = databaseFactory(options.config);
  let poller: RuntimeHandle | undefined;
  const close = idempotentClose(async (): Promise<void> => {
    const currentPoller = poller;

    await closeInOrder('worker resource shutdown', [
      ...(currentPoller === undefined
        ? []
        : [{
            name: 'workflow poller',
            close: (): Promise<void> => currentPoller.close(),
          }]),
      {
        name: 'database',
        close: (): Promise<void> => database.destroy(),
      },
    ]);
  });

  try {
    if (options.runMigrations !== false) {
      await migrateDatabase(database);
    }

    const outbox = new OutboxRepository();
    const configuredOutboxDispatcher = new OutboxDispatcher(
      options.config,
      database,
      outbox,
      new LaravelWebhookClient(options.config),
      options.logger,
    );
    const configuredEvaluationDispatcher = new OpportunityEvaluationDispatcher(
      options.config,
      database,
      new OpportunityEvaluationRepository(),
      outbox,
      options.logger,
    );
    poller = pollerStarter({
      intervalMs: options.config.outboxPollIntervalMs,
      logger: options.logger,
      outboxDispatcher: configuredOutboxDispatcher,
      evaluationDispatcher: configuredEvaluationDispatcher,
    });
  } catch (error) {
    try {
      await close();
    } catch (cleanupError) {
      throw new AggregateError(
        [error, cleanupError],
        'Worker startup and cleanup failed',
        { cause: cleanupError },
      );
    }

    throw error;
  }

  options.logger.info(
    {
      intervalMs: options.config.outboxPollIntervalMs,
    },
    'PostgreSQL workflow worker started',
  );

  return { close };
}
