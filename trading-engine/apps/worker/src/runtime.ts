import { Worker } from 'bullmq';
import type { Redis } from 'ioredis';
import type { Logger } from 'pino';

import type { EngineConfig } from '../../../src/config/env.js';
import { createDatabase } from '../../../src/infrastructure/database/client.js';
import { migrateDatabase } from '../../../src/infrastructure/database/migrate.js';
import { OpportunityEvaluationRepository } from '../../../src/infrastructure/database/repositories/opportunity-evaluation-repository.js';
import { OutboxRepository } from '../../../src/infrastructure/database/repositories/outbox-repository.js';
import { LaravelWebhookClient } from '../../../src/infrastructure/http/laravel-webhook-client.js';
import {
  closeRedis,
  createRedisConnection,
} from '../../../src/infrastructure/queue/connection.js';
import {
  OPPORTUNITY_EVALUATION_JOB,
  OUTBOX_DISPATCH_JOB,
  OUTBOX_QUEUE_NAME,
} from '../../../src/infrastructure/queue/names.js';
import {
  closeInOrder,
  idempotentClose,
  type RuntimeHandle,
} from '../../../src/infrastructure/runtime/process-lifecycle.js';
import { OpportunityEvaluationDispatcher } from '../../../src/workers/opportunity-evaluation-dispatcher.js';
import { OutboxDispatcher } from '../../../src/workers/outbox-dispatcher.js';

export interface StartWorkerOptions {
  readonly config: EngineConfig;
  readonly logger: Logger;
  readonly runMigrations?: boolean;
}

export async function startWorker(
  options: StartWorkerOptions,
): Promise<RuntimeHandle> {
  const database = createDatabase(options.config);
  let redis: Redis | undefined;
  let worker: Worker | undefined;
  let outboxDispatcher: OutboxDispatcher | undefined;
  let evaluationDispatcher: OpportunityEvaluationDispatcher | undefined;
  const close = idempotentClose(async (): Promise<void> => {
    const currentWorker = worker;
    const currentOutboxDispatcher = outboxDispatcher;
    const currentEvaluationDispatcher = evaluationDispatcher;
    const currentRedis = redis;

    await closeInOrder('worker resource shutdown', [
      ...(currentWorker === undefined
        ? []
        : [{
            name: 'worker',
            close: (): Promise<void> => currentWorker.close(),
          }]),
      ...(currentOutboxDispatcher === undefined
        ? []
        : [{
            name: 'outbox dispatcher',
            close: (): Promise<void> => currentOutboxDispatcher.shutdown(),
          }]),
      ...(currentEvaluationDispatcher === undefined
        ? []
        : [{
            name: 'evaluation dispatcher',
            close: (): Promise<void> => currentEvaluationDispatcher.shutdown(),
          }]),
      ...(currentRedis === undefined
        ? []
        : [{
            name: 'redis',
            close: (): Promise<void> => closeRedis(currentRedis),
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

    redis = createRedisConnection(options.config);
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
    outboxDispatcher = configuredOutboxDispatcher;
    evaluationDispatcher = configuredEvaluationDispatcher;
    worker = new Worker(
      OUTBOX_QUEUE_NAME,
      async (job) => {
        if (job.name === OUTBOX_DISPATCH_JOB) {
          return configuredOutboxDispatcher.dispatchBatch();
        }

        if (job.name === OPPORTUNITY_EVALUATION_JOB) {
          return configuredEvaluationDispatcher.dispatchBatch();
        }

        throw new Error(`Unsupported worker job: ${job.name}`);
      },
      {
        connection: redis,
        concurrency: 1,
        prefix: options.config.redisPrefix,
      },
    );

    worker.on('completed', (job, result) => {
      options.logger.debug(
        { jobId: job.id, result },
        'engine workflow job completed',
      );
    });
    worker.on('failed', (job, error) => {
      options.logger.error(
        { jobId: job?.id, err: error },
        'engine workflow job failed',
      );
    });
    worker.on('error', (error) => {
      options.logger.error({ err: error }, 'bullmq worker error');
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
      queue: OUTBOX_QUEUE_NAME,
      concurrency: 1,
    },
    'worker started',
  );

  return { close };
}
