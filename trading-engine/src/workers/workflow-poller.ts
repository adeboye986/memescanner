import type { Logger } from 'pino';

import {
  closeInOrder,
  idempotentClose,
  type RuntimeHandle,
} from '../infrastructure/runtime/process-lifecycle.js';

export interface WorkflowDispatchResult {
  readonly didWork: boolean;
}

export interface WorkflowDispatcher {
  dispatchBatch(): Promise<WorkflowDispatchResult>;
  shutdown(): Promise<void>;
}

export interface WorkflowPollerOptions {
  readonly intervalMs: number;
  readonly idleMaxIntervalMs: number;
  readonly logger: Logger;
  readonly outboxDispatcher: WorkflowDispatcher;
  readonly evaluationDispatcher: WorkflowDispatcher;
  readonly beforeCycle?: () => Promise<boolean>;
}

export interface WorkflowPollerHandle extends RuntimeHandle {
  readonly completed: Promise<void>;
}

type DispatchOutcome = 'active' | 'idle' | 'unknown';

export function startWorkflowPoller(
  options: WorkflowPollerOptions,
): WorkflowPollerHandle {
  let stopping = false;
  let idleIntervalMs = options.intervalMs;
  let timer: NodeJS.Timeout | undefined;
  let releaseWait: (() => void) | undefined;

  const waitForNextCycle = (delayMs: number): Promise<void> => new Promise((resolve) => {
    const complete = (): void => {
      if (timer !== undefined) {
        clearTimeout(timer);
      }

      timer = undefined;
      releaseWait = undefined;
      resolve();
    };

    timer = setTimeout(complete, delayMs);
    releaseWait = complete;
  });

  const dispatch = async (
    workflow: string,
    dispatcher: WorkflowDispatcher,
  ): Promise<DispatchOutcome> => {
    try {
      const result = await dispatcher.dispatchBatch();

      return result.didWork ? 'active' : 'idle';
    } catch (error) {
      options.logger.error(
        { err: error, workflow },
        'PostgreSQL workflow polling cycle failed',
      );

      return 'unknown';
    }
  };

  const runCycle = async (): Promise<DispatchOutcome> => {
    const outbox = await dispatch('outbox', options.outboxDispatcher);
    const evaluation = await dispatch(
      'opportunity-evaluation',
      options.evaluationDispatcher,
    );

    if (outbox === 'active' || evaluation === 'active') {
      return 'active';
    }

    if (outbox === 'unknown' || evaluation === 'unknown') {
      return 'unknown';
    }

    return 'idle';
  };

  const nextDelay = (outcome: DispatchOutcome): number => {
    if (outcome !== 'idle') {
      idleIntervalMs = options.intervalMs;

      return options.intervalMs;
    }

    const delayMs = idleIntervalMs;
    idleIntervalMs = Math.min(
      options.idleMaxIntervalMs,
      idleIntervalMs * 2,
    );

    return delayMs;
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

      const outcome = await runCycle();

      if (isRunning()) {
        await waitForNextCycle(nextDelay(outcome));
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
