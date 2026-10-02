import type { Logger } from 'pino';

import {
  startWorker,
  type StartWorkerOptions,
} from '../../worker/src/runtime.js';
import type { EngineConfig } from '../../../src/config/env.js';
import type { RuntimeHandle } from '../../../src/infrastructure/runtime/process-lifecycle.js';

export interface StartSchedulerOptions {
  readonly config: EngineConfig;
  readonly logger: Logger;
  readonly workflowStarter?: (
    options: StartWorkerOptions,
  ) => Promise<RuntimeHandle>;
}

export async function startScheduler(
  options: StartSchedulerOptions,
): Promise<RuntimeHandle> {
  const workflowStarter = options.workflowStarter ?? startWorker;
  const runtime = await workflowStarter({
    config: options.config,
    logger: options.logger,
    runMigrations: false,
  });

  options.logger.info(
    { intervalMs: options.config.outboxPollIntervalMs },
    'PostgreSQL workflow scheduler started',
  );

  return runtime;
}
