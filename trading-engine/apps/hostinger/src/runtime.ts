import type { Logger } from 'pino';

import {
  startApi,
  type StartApiOptions,
} from '../../api/src/runtime.js';
import {
  startWorker,
  type StartWorkerOptions,
} from '../../worker/src/runtime.js';
import type { EngineConfig } from '../../../src/config/env.js';
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
  readonly apiStarter?: (options: StartApiOptions) => Promise<RuntimeHandle>;
  readonly workerStarter?: (options: StartWorkerOptions) => Promise<RuntimeHandle>;
}

interface StartedComponent {
  readonly name: string;
  readonly handle: RuntimeHandle;
}

export async function startHostingerRuntime(
  dependencies: HostingerRuntimeDependencies,
): Promise<RuntimeHandle> {
  const apiStarter = dependencies.apiStarter ?? startApi;
  const workerStarter = dependencies.workerStarter ?? startWorker;
  const started: StartedComponent[] = [];

  try {
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

    const close = idempotentClose(async (): Promise<void> => {
      await closeInOrder('combined runtime shutdown', [
        { name: 'api', close: (): Promise<void> => api.close() },
        { name: 'worker', close: (): Promise<void> => worker.close() },
        {
          name: 'telemetry',
          close: (): Promise<void> => dependencies.telemetry.shutdown(),
        },
      ]);
    });

    dependencies.logger.info(
      { roles: ['api', 'worker'] },
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
        { cause: cleanupError },
      );
    }

    throw error;
  }
}
