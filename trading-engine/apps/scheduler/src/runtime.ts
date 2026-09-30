import { Queue, type JobsOptions } from 'bullmq';
import type { Redis } from 'ioredis';
import type { Logger } from 'pino';

import type { EngineConfig } from '../../../src/config/env.js';
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

export interface WorkflowQueue {
  add(
    name: string,
    data: { readonly scheduledAt: string },
    options: JobsOptions,
  ): Promise<unknown>;
}

export interface StartSchedulerOptions {
  readonly config: EngineConfig;
  readonly logger: Logger;
}

export async function scheduleWorkflows(
  queue: WorkflowQueue,
  pollIntervalMs: number,
  now: Date = new Date(),
): Promise<void> {
  const bucket = Math.floor(now.getTime() / pollIntervalMs);
  const data = { scheduledAt: now.toISOString() };
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
  } satisfies JobsOptions;

  await Promise.all([
    queue.add(
      OUTBOX_DISPATCH_JOB,
      data,
      { ...options, jobId: `outbox-dispatch-${bucket}` },
    ),
    queue.add(
      OPPORTUNITY_EVALUATION_JOB,
      data,
      { ...options, jobId: `opportunity-evaluation-${bucket}` },
    ),
  ]);
}

export async function startScheduler(
  options: StartSchedulerOptions,
): Promise<RuntimeHandle> {
  let redis: Redis | undefined;
  let queue: Queue | undefined;
  let interval: NodeJS.Timeout | undefined;
  const close = idempotentClose(async (): Promise<void> => {
    const currentQueue = queue;
    const currentRedis = redis;

    if (interval !== undefined) {
      clearInterval(interval);
    }

    await closeInOrder('scheduler resource shutdown', [
      ...(currentQueue === undefined
        ? []
        : [{ name: 'queue', close: (): Promise<void> => currentQueue.close() }]),
      ...(currentRedis === undefined
        ? []
        : [{
            name: 'redis',
            close: (): Promise<void> => closeRedis(currentRedis),
          }]),
    ]);
  });

  try {
    redis = createRedisConnection(options.config);
    queue = new Queue(OUTBOX_QUEUE_NAME, {
      connection: redis,
      prefix: options.config.redisPrefix,
    });
    await scheduleWorkflows(queue, options.config.outboxPollIntervalMs);
    interval = setInterval(() => {
      if (queue === undefined) {
        return;
      }

      void scheduleWorkflows(
        queue,
        options.config.outboxPollIntervalMs,
      ).catch((error: unknown) => {
        options.logger.error(
          { err: error },
          'failed to schedule engine workflows',
        );
      });
    }, options.config.outboxPollIntervalMs);
    interval.unref();
  } catch (error) {
    try {
      await close();
    } catch (cleanupError) {
      throw new AggregateError(
        [error, cleanupError],
        'Scheduler startup and cleanup failed',
        { cause: error },
      );
    }

    throw error;
  }

  options.logger.info(
    {
      queue: OUTBOX_QUEUE_NAME,
      intervalMs: options.config.outboxPollIntervalMs,
    },
    'scheduler started',
  );

  return { close };
}
