import { existsSync } from 'node:fs';
import { loadEnvFile } from 'node:process';

import { Worker } from 'bullmq';

import { createLogger } from '../../api/src/app.js';
import { loadConfig } from '../../../src/config/env.js';
import { createDatabase } from '../../../src/infrastructure/database/client.js';
import { migrateDatabase } from '../../../src/infrastructure/database/migrate.js';
import { OutboxRepository } from '../../../src/infrastructure/database/repositories/outbox-repository.js';
import { LaravelWebhookClient } from '../../../src/infrastructure/http/laravel-webhook-client.js';
import {
  closeRedis,
  createRedisConnection,
} from '../../../src/infrastructure/queue/connection.js';
import {
  OUTBOX_DISPATCH_JOB,
  OUTBOX_QUEUE_NAME,
} from '../../../src/infrastructure/queue/names.js';
import { startTelemetry } from '../../../src/infrastructure/telemetry/instrumentation.js';
import { OutboxDispatcher } from '../../../src/workers/outbox-dispatcher.js';

if (existsSync('.env')) {
  loadEnvFile('.env');
}

const config = loadConfig();
const logger = createLogger(config);
const telemetry = startTelemetry(config);
const database = createDatabase(config);
await migrateDatabase(database);
const redis = createRedisConnection(config);
const dispatcher = new OutboxDispatcher(
  config,
  database,
  new OutboxRepository(),
  new LaravelWebhookClient(config),
  logger,
);
const worker = new Worker(
  OUTBOX_QUEUE_NAME,
  async (job) => {
    if (job.name !== OUTBOX_DISPATCH_JOB) {
      throw new Error(`Unsupported worker job: ${job.name}`);
    }

    return dispatcher.dispatchBatch();
  },
  {
    connection: redis,
    concurrency: 1,
    prefix: config.redisPrefix,
  },
);
let shuttingDown = false;

worker.on('completed', (job, result) => {
  logger.debug({ jobId: job.id, result }, 'outbox job completed');
});
worker.on('failed', (job, error) => {
  logger.error({ jobId: job?.id, err: error }, 'outbox job failed');
});
worker.on('error', (error) => {
  logger.error({ err: error }, 'bullmq worker error');
});

async function shutdown(signal: string): Promise<void> {
  if (shuttingDown) {
    return;
  }

  shuttingDown = true;
  logger.info({ signal }, 'worker shutdown started');
  const deadline = setTimeout(() => {
    logger.fatal({ signal }, 'worker shutdown deadline exceeded');
    process.exit(1);
  }, config.shutdownTimeoutMs);
  deadline.unref();

  try {
    await worker.close();
    await dispatcher.shutdown();
    await closeRedis(redis);
    await database.destroy();
    await telemetry.shutdown();
    clearTimeout(deadline);
    logger.info({ signal }, 'worker shutdown completed');
  } catch (error) {
    logger.error({ err: error, signal }, 'worker shutdown failed');
    process.exitCode = 1;
  }
}

process.once('SIGINT', () => {
  void shutdown('SIGINT');
});
process.once('SIGTERM', () => {
  void shutdown('SIGTERM');
});

logger.info(
  {
    queue: OUTBOX_QUEUE_NAME,
    concurrency: 1,
  },
  'worker started',
);
