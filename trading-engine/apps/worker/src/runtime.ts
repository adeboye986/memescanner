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
import {
  startPaperMarketMonitorRuntime,
  type PaperMarketMonitorRuntimeHandle,
  type PaperMarketMonitorRuntimeOptions,
} from '../../../src/workers/paper-market-monitor-runtime.js';
import { OpportunityEvaluationDispatcher } from '../../../src/workers/opportunity-evaluation-dispatcher.js';
import { OutboxDispatcher } from '../../../src/workers/outbox-dispatcher.js';
import {
  PostgresWorkflowLeadershipBackend,
  startPostgresWorkflowLeader,
  type PostgresWorkflowLeaderOptions,
} from '../../../src/workers/postgres-workflow-leader.js';
import {
  startWorkflowPoller,
  type WorkflowPollerHandle,
  type WorkflowPollerOptions,
} from '../../../src/workers/workflow-poller.js';

export interface StartWorkerOptions {
  readonly config: EngineConfig;
  readonly logger: Logger;
  readonly runMigrations?: boolean;
}

export interface StartWorkerDependencies {
  readonly databaseFactory?: typeof createDatabase;
  readonly leadershipStarter?: (options: PostgresWorkflowLeaderOptions) => RuntimeHandle;
  readonly pollerStarter?: (options: WorkflowPollerOptions) => WorkflowPollerHandle;
  readonly paperMarketMonitorStarter?: (
    options: PaperMarketMonitorRuntimeOptions,
  ) => PaperMarketMonitorRuntimeHandle;
}

export async function startWorker(
  options: StartWorkerOptions,
  dependencies: StartWorkerDependencies = {},
): Promise<RuntimeHandle> {
  const databaseFactory = dependencies.databaseFactory ?? createDatabase;
  const leadershipStarter = dependencies.leadershipStarter ?? startPostgresWorkflowLeader;
  const pollerStarter = dependencies.pollerStarter ?? startWorkflowPoller;
  const paperMarketMonitorStarter = dependencies.paperMarketMonitorStarter
    ?? startPaperMarketMonitorRuntime;
  const database = databaseFactory(options.config);
  let leadership: RuntimeHandle | undefined;
  const close = idempotentClose(async (): Promise<void> => {
    const currentLeadership = leadership;

    await closeInOrder('worker resource shutdown', [
      ...(currentLeadership === undefined
        ? []
        : [{
            name: 'workflow leadership',
            close: (): Promise<void> => currentLeadership.close(),
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

    leadership = leadershipStarter({
      backend: new PostgresWorkflowLeadershipBackend(database),
      retryIntervalMs: options.config.workflowLeaderRetryIntervalMs,
      logger: options.logger,
      startPoller: ({ database: leadershipDatabase, beforeCycle }) => {
        const outbox = new OutboxRepository();
        const workflowPoller = pollerStarter({
          intervalMs: options.config.outboxPollIntervalMs,
          idleMaxIntervalMs: options.config.workflowIdleMaxIntervalMs,
          logger: options.logger,
          beforeCycle,
          outboxDispatcher: new OutboxDispatcher(
            options.config,
            leadershipDatabase,
            outbox,
            new LaravelWebhookClient(options.config),
            options.logger,
          ),
          evaluationDispatcher: new OpportunityEvaluationDispatcher(
            options.config,
            leadershipDatabase,
            new OpportunityEvaluationRepository(),
            outbox,
            options.logger,
          ),
        });

        if (!options.config.paperMarketMonitoringEnabled) {
          return workflowPoller;
        }

        const paperMarketMonitor = paperMarketMonitorStarter({
          config: options.config,
          database: leadershipDatabase,
          logger: options.logger,
          beforeCycle,
        });

        return combineLeaderPollers(workflowPoller, paperMarketMonitor);
      },
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
      idleMaxIntervalMs: options.config.workflowIdleMaxIntervalMs,
      leaderRetryIntervalMs: options.config.workflowLeaderRetryIntervalMs,
    },
    'PostgreSQL workflow worker started',
  );

  return { close };
}

function combineLeaderPollers(
  workflowPoller: WorkflowPollerHandle,
  paperMarketMonitor: PaperMarketMonitorRuntimeHandle,
): WorkflowPollerHandle {
  const closePaperMarketMonitor = idempotentClose(
    (): Promise<void> => paperMarketMonitor.close(),
  );
  const completed = (async (): Promise<void> => {
    await workflowPoller.completed;
    await closePaperMarketMonitor();
  })();
  const close = idempotentClose(async (): Promise<void> => {
    await closeInOrder('leader-controlled poller shutdown', [
      {
        name: 'workflow poller',
        close: (): Promise<void> => workflowPoller.close(),
      },
      {
        name: 'PAPER market monitor',
        close: closePaperMarketMonitor,
      },
    ]);
  });

  return { close, completed };
}
