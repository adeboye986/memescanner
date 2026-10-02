import type { Logger } from 'pino';

import {
  closeInOrder,
  idempotentClose,
  type RuntimeHandle,
} from '../infrastructure/runtime/process-lifecycle.js';

export interface WorkflowDispatcher {
  dispatchBatch(): Promise<unknown>;
  shutdown(): Promise<void>;
}

export interface WorkflowPollerOptions {
  readonly intervalMs: number;
  readonly logger: Logger;
  readonly outboxDispatcher: WorkflowDispatcher;
  readonly evaluationDispatcher: WorkflowDispatcher;
  readonly beforeCycle?: () => Promise<boolean>;
}

export interface WorkflowPollerHandle extends RuntimeHandle {
  readonly completed: Promise<void>;
}

export function startWorkflowPoller(
  options: WorkflowPollerOptions,
): WorkflowPollerHandle {
  let stopping = false;
  let timer: NodeJS.Timeout | undefined;
  let releaseWait: (() => void) | undefined;

  const waitForNextCycle = (): Promise<void> => new Promise((resolve) => {
    const complete = (): void => {
      if (timer !== undefined) {
        clearTimeout(timer);
      }

      timer = undefined;
      releaseWait = undefined;
      resolve();
    };

    timer = setTimeout(complete, options.intervalMs);
    releaseWait = complete;
  });

  const dispatch = async (
    workflow: string,
    dispatcher: WorkflowDispatcher,
  ): Promise<void> => {
    try {
      await dispatcher.dispatchBatch();
    } catch (error) {
      options.logger.error(
        { err: error, workflow },
        'PostgreSQL workflow polling cycle failed',
      );
    }
  };

  const runCycle = async (): Promise<void> => {
    await dispatch('outbox', options.outboxDispatcher);
    await dispatch('opportunity-evaluation', options.evaluationDispatcher);
  };

  const isRunning = (): boolean => !stopping;
  const run = async (): Promise<void> => {
    while (isRunning()) {
      if (options.beforeCycle !== undefined) {
        try {
          if (!await options.beforeCycle()) {
            options.logger.warn('PostgreSQL workflow leadership was lost');
            break;
          }
        } catch (error) {
          options.logger.error(
            { err: error },
            'PostgreSQL workflow leadership check failed',
          );
          break;
        }
      }

      await runCycle();

      if (isRunning()) {
        await waitForNextCycle();
      }
    }
  };

  const runPromise = run();
  const close = idempotentClose(async (): Promise<void> => {
    stopping = true;
    releaseWait?.();
    await runPromise;
    await closeInOrder('workflow poller shutdown', [
      {
        name: 'outbox dispatcher',
        close: (): Promise<void> => options.outboxDispatcher.shutdown(),
      },
      {
        name: 'evaluation dispatcher',
        close: (): Promise<void> => options.evaluationDispatcher.shutdown(),
      },
    ]);
  });

  return { close, completed: runPromise };
}
