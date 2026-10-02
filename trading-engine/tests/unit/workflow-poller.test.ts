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
  type WorkflowDispatchResult,
} from '../../src/workers/workflow-poller.js';

const idleResult: WorkflowDispatchResult = { didWork: false };
const activeResult: WorkflowDispatchResult = { didWork: true };

interface TestDispatcher {
  readonly dispatcher: WorkflowDispatcher;
  readonly dispatchBatch: Mock<WorkflowDispatcher['dispatchBatch']>;
  readonly shutdown: Mock<WorkflowDispatcher['shutdown']>;
}

function testDispatcher(
  implementation: WorkflowDispatcher['dispatchBatch'] = () =>
    Promise.resolve(idleResult),
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
    const outbox = testDispatcher((): Promise<WorkflowDispatchResult> => {
      events.push('outbox');

      return Promise.resolve(idleResult);
    });
    const evaluation = testDispatcher((): Promise<WorkflowDispatchResult> => {
      events.push('evaluation');

      return Promise.resolve(idleResult);
    });
    const poller = startWorkflowPoller({
      intervalMs: 1_000,
      idleMaxIntervalMs: 4_000,
      logger: pino({ level: 'silent' }),
      outboxDispatcher: outbox.dispatcher,
      evaluationDispatcher: evaluation.dispatcher,
    });

    await flushMicrotasks();

    expect(events).toEqual(['outbox', 'evaluation']);
    await poller.close();
  });

  it('keeps the active interval while work continues', async () => {
    const outbox = testDispatcher(() => Promise.resolve(activeResult));
    const evaluation = testDispatcher();
    const poller = startWorkflowPoller({
      intervalMs: 1_000,
      idleMaxIntervalMs: 4_000,
      logger: pino({ level: 'silent' }),
      outboxDispatcher: outbox.dispatcher,
      evaluationDispatcher: evaluation.dispatcher,
    });

    await flushMicrotasks();
    await vi.advanceTimersByTimeAsync(999);
    expect(outbox.dispatchBatch).toHaveBeenCalledOnce();

    await vi.advanceTimersByTimeAsync(1);
    expect(outbox.dispatchBatch).toHaveBeenCalledTimes(2);

    await vi.advanceTimersByTimeAsync(1_000);
    expect(outbox.dispatchBatch).toHaveBeenCalledTimes(3);
    await poller.close();
  });

  it('backs off consecutive idle cycles and caps the delay', async () => {
    const outbox = testDispatcher();
    const evaluation = testDispatcher();
    const poller = startWorkflowPoller({
      intervalMs: 1_000,
      idleMaxIntervalMs: 4_000,
      logger: pino({ level: 'silent' }),
      outboxDispatcher: outbox.dispatcher,
      evaluationDispatcher: evaluation.dispatcher,
    });

    await flushMicrotasks();
    expect(outbox.dispatchBatch).toHaveBeenCalledOnce();

    await vi.advanceTimersByTimeAsync(1_000);
    expect(outbox.dispatchBatch).toHaveBeenCalledTimes(2);

    await vi.advanceTimersByTimeAsync(1_999);
    expect(outbox.dispatchBatch).toHaveBeenCalledTimes(2);
    await vi.advanceTimersByTimeAsync(1);
    expect(outbox.dispatchBatch).toHaveBeenCalledTimes(3);

    await vi.advanceTimersByTimeAsync(3_999);
    expect(outbox.dispatchBatch).toHaveBeenCalledTimes(3);
    await vi.advanceTimersByTimeAsync(1);
    expect(outbox.dispatchBatch).toHaveBeenCalledTimes(4);

    await vi.advanceTimersByTimeAsync(4_000);
    expect(outbox.dispatchBatch).toHaveBeenCalledTimes(5);
    await poller.close();
  });

  it('resets immediately to the active interval when work follows idle cycles', async () => {
    let attempts = 0;
    const outbox = testDispatcher((): Promise<WorkflowDispatchResult> => {
      attempts += 1;

      return Promise.resolve(attempts === 3 ? activeResult : idleResult);
    });
    const evaluation = testDispatcher();
    const poller = startWorkflowPoller({
      intervalMs: 1_000,
      idleMaxIntervalMs: 4_000,
      logger: pino({ level: 'silent' }),
      outboxDispatcher: outbox.dispatcher,
      evaluationDispatcher: evaluation.dispatcher,
    });

    await flushMicrotasks();
    await vi.advanceTimersByTimeAsync(1_000);
    await vi.advanceTimersByTimeAsync(2_000);
    expect(outbox.dispatchBatch).toHaveBeenCalledTimes(3);

    await vi.advanceTimersByTimeAsync(999);
    expect(outbox.dispatchBatch).toHaveBeenCalledTimes(3);
    await vi.advanceTimersByTimeAsync(1);
    expect(outbox.dispatchBatch).toHaveBeenCalledTimes(4);
    await poller.close();
  });

  it('uses the active interval after an unexpected dispatcher failure', async () => {
    let outboxAttempts = 0;
    const outbox = testDispatcher((): Promise<WorkflowDispatchResult> => {
      outboxAttempts += 1;

      return outboxAttempts === 1
        ? Promise.reject(new Error('synthetic cycle failure'))
        : Promise.resolve(idleResult);
    });
    const evaluation = testDispatcher();
    const logger = pino({ level: 'silent' });
    const logError = vi.spyOn(logger, 'error');
    const poller = startWorkflowPoller({
      intervalMs: 1_000,
      idleMaxIntervalMs: 4_000,
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
    expect(outbox.dispatchBatch).toHaveBeenCalledTimes(2);
    expect(evaluation.dispatchBatch).toHaveBeenCalledTimes(2);
    await poller.close();
  });

  it('never overlaps polling cycles', async () => {
    const activeOutbox = deferredVoid();
    const outbox = testDispatcher(async (): Promise<WorkflowDispatchResult> => {
      await activeOutbox.promise;

      return idleResult;
    });
    const evaluation = testDispatcher();
    const poller = startWorkflowPoller({
      intervalMs: 1_000,
      idleMaxIntervalMs: 4_000,
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

  it('interrupts an adaptive wait and shuts down dispatchers exactly once', async () => {
    const outbox = testDispatcher();
    const evaluation = testDispatcher();
    const poller = startWorkflowPoller({
      intervalMs: 1_000,
      idleMaxIntervalMs: 4_000,
      logger: pino({ level: 'silent' }),
      outboxDispatcher: outbox.dispatcher,
      evaluationDispatcher: evaluation.dispatcher,
    });

    await flushMicrotasks();
    await vi.advanceTimersByTimeAsync(1_000);
    await vi.advanceTimersByTimeAsync(2_000);
    expect(outbox.dispatchBatch).toHaveBeenCalledTimes(3);

    await Promise.all([poller.close(), poller.close()]);
    await vi.advanceTimersByTimeAsync(10_000);

    expect(outbox.dispatchBatch).toHaveBeenCalledTimes(3);
    expect(evaluation.dispatchBatch).toHaveBeenCalledTimes(3);
    expect(outbox.shutdown).toHaveBeenCalledOnce();
    expect(evaluation.shutdown).toHaveBeenCalledOnce();
  });

  it('awaits the active cycle before releasing dispatcher claims', async () => {
    const activeOutbox = deferredVoid();
    const activeEvaluation = deferredVoid();
    const events: string[] = [];
    const outbox = testDispatcher(async (): Promise<WorkflowDispatchResult> => {
      await activeOutbox.promise;
      events.push('outbox completed');

      return idleResult;
    });
    outbox.shutdown.mockImplementation((): Promise<void> => {
      events.push('outbox shutdown');

      return Promise.resolve();
    });
    const evaluation = testDispatcher(async (): Promise<WorkflowDispatchResult> => {
      await activeEvaluation.promise;
      events.push('evaluation completed');

      return idleResult;
    });
    evaluation.shutdown.mockImplementation((): Promise<void> => {
      events.push('evaluation shutdown');

      return Promise.resolve();
    });
    const poller = startWorkflowPoller({
      intervalMs: 1_000,
      idleMaxIntervalMs: 4_000,
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
      idleMaxIntervalMs: 4_000,
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
