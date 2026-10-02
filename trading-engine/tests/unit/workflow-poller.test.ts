import pino from 'pino';
import {
  afterEach,
  beforeEach,
  describe,
  expect,
  it,
  type Mock,
  vi,
} from 'vitest';

import {
  startWorkflowPoller,
  type WorkflowDispatcher,
} from '../../src/workers/workflow-poller.js';

interface TestDispatcher {
  readonly dispatcher: WorkflowDispatcher;
  readonly dispatchBatch: Mock<WorkflowDispatcher['dispatchBatch']>;
  readonly shutdown: Mock<WorkflowDispatcher['shutdown']>;
}

function testDispatcher(
  implementation: WorkflowDispatcher['dispatchBatch'] = () =>
    Promise.resolve(undefined),
): TestDispatcher {
  const dispatchBatch = vi.fn(implementation);
  const shutdown = vi.fn((): Promise<void> => Promise.resolve());

  return {
    dispatcher: { dispatchBatch, shutdown } satisfies WorkflowDispatcher,
    dispatchBatch,
    shutdown,
  };
}

function deferredVoid(): {
  readonly promise: Promise<void>;
  readonly resolve: () => void;
} {
  let release = (): void => {
    throw new Error('Deferred promise was resolved before initialization');
  };
  const promise = new Promise<void>((resolve) => {
    release = resolve;
  });

  return { promise, resolve: release };
}

async function flushMicrotasks(): Promise<void> {
  await Promise.resolve();
  await Promise.resolve();
}

describe('PostgreSQL workflow poller', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('runs an immediate sequential sweep of both dispatchers', async () => {
    const events: string[] = [];
    const outbox = testDispatcher((): Promise<void> => {
      events.push('outbox');

      return Promise.resolve();
    });
    const evaluation = testDispatcher((): Promise<void> => {
      events.push('evaluation');

      return Promise.resolve();
    });
    const poller = startWorkflowPoller({
      intervalMs: 1_000,
      logger: pino({ level: 'silent' }),
      outboxDispatcher: outbox.dispatcher,
      evaluationDispatcher: evaluation.dispatcher,
    });

    await flushMicrotasks();

    expect(events).toEqual(['outbox', 'evaluation']);
    await poller.close();
  });

  it('never overlaps polling cycles', async () => {
    const activeOutbox = deferredVoid();
    const outbox = testDispatcher((): Promise<void> => activeOutbox.promise);
    const evaluation = testDispatcher();
    const poller = startWorkflowPoller({
      intervalMs: 1_000,
      logger: pino({ level: 'silent' }),
      outboxDispatcher: outbox.dispatcher,
      evaluationDispatcher: evaluation.dispatcher,
    });

    await flushMicrotasks();
    await vi.advanceTimersByTimeAsync(5_000);

    expect(outbox.dispatchBatch).toHaveBeenCalledOnce();
    expect(evaluation.dispatchBatch).not.toHaveBeenCalled();

    activeOutbox.resolve();
    await flushMicrotasks();

    expect(evaluation.dispatchBatch).toHaveBeenCalledOnce();
    await poller.close();
  });

  it('waits before continuing after an unexpected cycle failure', async () => {
    let outboxAttempts = 0;
    const outbox = testDispatcher((): Promise<void> => {
      outboxAttempts += 1;

      return outboxAttempts === 1
        ? Promise.reject(new Error('synthetic cycle failure'))
        : Promise.resolve();
    });
    const evaluation = testDispatcher();
    const logger = pino({ level: 'silent' });
    const logError = vi.spyOn(logger, 'error');
    const poller = startWorkflowPoller({
      intervalMs: 1_000,
      logger,
      outboxDispatcher: outbox.dispatcher,
      evaluationDispatcher: evaluation.dispatcher,
    });

    await flushMicrotasks();

    expect(outbox.dispatchBatch).toHaveBeenCalledOnce();
    expect(evaluation.dispatchBatch).toHaveBeenCalledOnce();
    expect(logError).toHaveBeenCalledOnce();

    await vi.advanceTimersByTimeAsync(999);
    expect(outbox.dispatchBatch).toHaveBeenCalledOnce();

    await vi.advanceTimersByTimeAsync(1);
    await flushMicrotasks();

    expect(outbox.dispatchBatch).toHaveBeenCalledTimes(2);
    expect(evaluation.dispatchBatch).toHaveBeenCalledTimes(2);
    await poller.close();
  });

  it('stops future cycles and shuts down dispatchers exactly once', async () => {
    const outbox = testDispatcher();
    const evaluation = testDispatcher();
    const poller = startWorkflowPoller({
      intervalMs: 1_000,
      logger: pino({ level: 'silent' }),
      outboxDispatcher: outbox.dispatcher,
      evaluationDispatcher: evaluation.dispatcher,
    });

    await flushMicrotasks();
    await Promise.all([poller.close(), poller.close()]);
    await vi.advanceTimersByTimeAsync(5_000);

    expect(outbox.dispatchBatch).toHaveBeenCalledOnce();
    expect(evaluation.dispatchBatch).toHaveBeenCalledOnce();
    expect(outbox.shutdown).toHaveBeenCalledOnce();
    expect(evaluation.shutdown).toHaveBeenCalledOnce();
  });

  it('awaits the active cycle before releasing dispatcher claims', async () => {
    const activeOutbox = deferredVoid();
    const activeEvaluation = deferredVoid();
    const events: string[] = [];
    const outbox = testDispatcher(async (): Promise<void> => {
      await activeOutbox.promise;
      events.push('outbox completed');
    });
    outbox.shutdown.mockImplementation((): Promise<void> => {
      events.push('outbox shutdown');

      return Promise.resolve();
    });
    const evaluation = testDispatcher(async (): Promise<void> => {
      await activeEvaluation.promise;
      events.push('evaluation completed');
    });
    evaluation.shutdown.mockImplementation((): Promise<void> => {
      events.push('evaluation shutdown');

      return Promise.resolve();
    });
    const poller = startWorkflowPoller({
      intervalMs: 1_000,
      logger: pino({ level: 'silent' }),
      outboxDispatcher: outbox.dispatcher,
      evaluationDispatcher: evaluation.dispatcher,
    });
    let closed = false;

    await flushMicrotasks();
    const closing = poller.close().then((): void => {
      closed = true;
    });
    await flushMicrotasks();

    expect(closed).toBe(false);
    activeOutbox.resolve();
    await flushMicrotasks();
    expect(evaluation.dispatchBatch).toHaveBeenCalledOnce();
    expect(closed).toBe(false);

    activeEvaluation.resolve();
    await closing;

    expect(events).toEqual([
      'outbox completed',
      'evaluation completed',
      'outbox shutdown',
      'evaluation shutdown',
    ]);
  });

  it('does not dispatch after its leadership guard fails', async () => {
    const outbox = testDispatcher();
    const evaluation = testDispatcher();
    const poller = startWorkflowPoller({
      intervalMs: 1_000,
      logger: pino({ level: 'silent' }),
      beforeCycle: (): Promise<boolean> => Promise.resolve(false),
      outboxDispatcher: outbox.dispatcher,
      evaluationDispatcher: evaluation.dispatcher,
    });

    await poller.completed;
    await poller.close();

    expect(outbox.dispatchBatch).not.toHaveBeenCalled();
    expect(evaluation.dispatchBatch).not.toHaveBeenCalled();
    expect(outbox.shutdown).toHaveBeenCalledOnce();
    expect(evaluation.shutdown).toHaveBeenCalledOnce();
  });
});
