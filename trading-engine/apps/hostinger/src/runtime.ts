import type { Logger } from 'pino';

import {
  startApi,
  type StartApiOptions,
} from '../../api/src/runtime.js';
import {
  startScheduler,
  type StartSchedulerOptions,
} from '../../scheduler/src/runtime.js';
import {
  startWorker,
  type StartWorkerOptions,
} from '../../worker/src/runtime.js';
import type { EngineConfig } from '../../../src/config/env.js';
import { createDatabase } from '../../../src/infrastructure/database/client.js';
import { migrateDatabase } from '../../../src/infrastructure/database/migrate.js';
import {
  closeInOrder,
  idempotentClose,
  type CloseStep,
  type RuntimeHandle,
} from '../../../src/infrastructure/runtime/process-lifecycle.js';
import type { Telemetry } from '../../../src/infrastructure/telemetry/instrumentation.js';

export interface HostingerRuntimeDependencies {
  readonly config: EngineConfig;
  readonly logger: Logger;
  readonly telemetry: Telemetry;
  readonly migrate?: (config: EngineConfig) => Promise<void>;
  readonly apiStarter?: (options: StartApiOptions) => Promise<RuntimeHandle>;
  readonly workerStarter?: (options: StartWorkerOptions) => Promise<RuntimeHandle>;
  readonly schedulerStarter?: (
    options: StartSchedulerOptions,
  ) => Promise<RuntimeHandle>;
}

interface StartedComponent {
  readonly name: string;
  readonly handle: RuntimeHandle;
}

async function migrateForCombinedRuntime(config: EngineConfig): Promise<void> {
  const database = createDatabase(config);

  try {
    await migrateDatabase(database);
  } finally {
    await database.destroy();
  }
}

export async function startHostingerRuntime(
  dependencies: HostingerRuntimeDependencies,
): Promise<RuntimeHandle> {
  const migrate = dependencies.migrate ?? migrateForCombinedRuntime;
  const apiStarter = dependencies.apiStarter ?? startApi;
  const workerStarter = dependencies.workerStarter ?? startWorker;
  const schedulerStarter = dependencies.schedulerStarter ?? startScheduler;
  const started: StartedComponent[] = [];

  try {
    await migrate(dependencies.config);
    const api = await apiStarter({
      config: dependencies.config,
      logger: dependencies.logger,
      runMigrations: false,
    });
    started.push({ name: 'api', handle: api });
    const worker = await workerStarter({
      config: dependencies.config,
      logger: dependencies.logger,
      runMigrations: false,
    });
    started.push({ name: 'worker', handle: worker });
    const scheduler = await schedulerStarter({
      config: dependencies.config,
      logger: dependencies.logger,
    });
    started.push({ name: 'scheduler', handle: scheduler });

    const close = idempotentClose(async (): Promise<void> => {
      await closeInOrder('combined runtime shutdown', [
        { name: 'scheduler', close: (): Promise<void> => scheduler.close() },
        { name: 'api', close: (): Promise<void> => api.close() },
        { name: 'worker', close: (): Promise<void> => worker.close() },
        {
          name: 'telemetry',
          close: (): Promise<void> => dependencies.telemetry.shutdown(),
        },
      ]);
    });

    dependencies.logger.info(
      { roles: ['api', 'worker', 'scheduler'] },
      'combined Hostinger runtime started',
    );

    return { close };
  } catch (error) {
    const cleanup = started
      .toReversed()
      .map(({ name, handle }): CloseStep => ({
        name,
        close: (): Promise<void> => handle.close(),
      }));
    cleanup.push({
      name: 'telemetry',
      close: (): Promise<void> => dependencies.telemetry.shutdown(),
    });

    try {
      await closeInOrder('combined startup cleanup', cleanup);
    } catch (cleanupError) {
      throw new AggregateError(
        [error, cleanupError],
        'Combined runtime startup and cleanup failed',
        { cause: error },
      );
    }

    throw error;
  }
}
