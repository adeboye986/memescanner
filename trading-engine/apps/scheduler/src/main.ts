import { existsSync } from 'node:fs';
import { loadEnvFile } from 'node:process';

import { Queue } from 'bullmq';

import { createLogger } from '../../api/src/app.js';
import { loadConfig } from '../../../src/config/env.js';
import {
  closeRedis,
  createRedisConnection,
} from '../../../src/infrastructure/queue/connection.js';
import {
  OPPORTUNITY_EVALUATION_JOB,
  OUTBOX_DISPATCH_JOB,
  OUTBOX_QUEUE_NAME,
} from '../../../src/infrastructure/queue/names.js';
import { startTelemetry } from '../../../src/infrastructure/telemetry/instrumentation.js';

if (existsSync('.env')) {
  loadEnvFile('.env');
}

const config = loadConfig();
const logger = createLogger(config);
const telemetry = startTelemetry(config);
const redis = createRedisConnection(config);
const queue = new Queue(OUTBOX_QUEUE_NAME, {
  connection: redis,
  prefix: config.redisPrefix,
});
let shuttingDown = false;

async function scheduleWorkflows(): Promise<void> {
  const bucket = Math.floor(Date.now() / config.outboxPollIntervalMs);
  const options = {
    removeOnComplete: {
      age: 3_600,
      count: 1_000,
    },
    removeOnFail: {
      age: 86_400,
      count: 1_000,
    },
    attempts: 3,
    backoff: {
      type: 'exponential' as const,
      delay: 500,
    },
  };

  await Promise.all([
    queue.add(
      OUTBOX_DISPATCH_JOB,
      { scheduledAt: new Date().toISOString() },
      { ...options, jobId: `outbox-dispatch-${bucket}` },
    ),
    queue.add(
      OPPORTUNITY_EVALUATION_JOB,
      { scheduledAt: new Date().toISOString() },
      { ...options, jobId: `opportunity-evaluation-${bucket}` },
    ),
  ]);
}

await scheduleWorkflows();
const interval = setInterval(() => {
  void scheduleWorkflows().catch((error: unknown) => {
    logger.error({ err: error }, 'failed to schedule engine workflows');
  });
}, config.outboxPollIntervalMs);
interval.unref();

async function shutdown(signal: string): Promise<void> {
  if (shuttingDown) {
    return;
  }

  shuttingDown = true;
  logger.info({ signal }, 'scheduler shutdown started');
  clearInterval(interval);
  const deadline = setTimeout(() => {
    logger.fatal({ signal }, 'scheduler shutdown deadline exceeded');
    process.exit(1);
  }, config.shutdownTimeoutMs);
  deadline.unref();

  try {
    await queue.close();
    await closeRedis(redis);
    await telemetry.shutdown();
    clearTimeout(deadline);
    logger.info({ signal }, 'scheduler shutdown completed');
  } catch (error) {
    logger.error({ err: error, signal }, 'scheduler shutdown failed');
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
    intervalMs: config.outboxPollIntervalMs,
  },
  'scheduler started',
);
