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
  PaperMarketMonitorPoller,
  type PaperMarketMonitoringCycleRunner,
} from '../../src/workers/paper-market-monitor-poller.js';
import type { PaperMarketMonitoringSummary } from '../../src/workers/paper-market-monitor.js';

const summary: PaperMarketMonitoringSummary = {
  enabled: true,
  retired: 0,
  claimed: 0,
  providerRequests: 0,
  succeeded: 0,
  failed: 0,
  completed: 0,
};

interface TestCycle {
  readonly cycle: PaperMarketMonitoringCycleRunner;
  readonly runCycle: Mock<PaperMarketMonitoringCycleRunner['runCycle']>;
  readonly shutdown: Mock<PaperMarketMonitoringCycleRunner['shutdown']>;
}

function testCycle(
  implementation: PaperMarketMonitoringCycleRunner['runCycle'] = () =>
    Promise.resolve(summary),
): TestCycle {
  const runCycle = vi.fn(implementation);
  const shutdown = vi.fn((): Promise<void> => Promise.resolve());

  return {
    cycle: { runCycle, shutdown },
    runCycle,
    shutdown,
  };
}

function deferred<T>(): {
  readonly promise: Promise<T>;
  readonly resolve: (value: T) => void;
} {
  let release: ((value: T) => void) | undefined;
  const promise = new Promise<T>((resolve) => {
    release = resolve;
  });

  return {
    promise,
    resolve: (value: T): void => {
      if (release === undefined) {
        throw new Error('Deferred promise was resolved before initialization');
      }

      release(value);
    },
  };
}

async function flushMicrotasks(): Promise<void> {
  for (let iteration = 0; iteration < 8; iteration += 1) {
    await Promise.resolve();
  }
}

describe('PAPER market monitor poller', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('does not start work until requested and then runs an immediate cycle', async () => {
    const monitoring = testCycle();
    const poller = new PaperMarketMonitorPoller({
      intervalMs: 1_000,
      logger: pino({ level: 'silent' }),
      cycle: monitoring.cycle,
    });

    expect(monitoring.runCycle).not.toHaveBeenCalled();
    expect(vi.getTimerCount()).toBe(0);

    poller.start();
    await flushMicrotasks();

    expect(monitoring.runCycle).toHaveBeenCalledOnce();
    await poller.close();
  });

  it('waits the full interval after completion and never overlaps cycles', async () => {
    const firstCycle = deferred<PaperMarketMonitoringSummary>();
    const monitoring = testCycle();
    monitoring.runCycle.mockImplementationOnce(() => firstCycle.promise);
    const poller = new PaperMarketMonitorPoller({
      intervalMs: 1_000,
      logger: pino({ level: 'silent' }),
      cycle: monitoring.cycle,
    });

    poller.start();
    await flushMicrotasks();
    await vi.advanceTimersByTimeAsync(5_000);
    expect(monitoring.runCycle).toHaveBeenCalledOnce();

    firstCycle.resolve(summary);
    await flushMicrotasks();
    await vi.advanceTimersByTimeAsync(999);
    expect(monitoring.runCycle).toHaveBeenCalledOnce();

    await vi.advanceTimersByTimeAsync(1);
    expect(monitoring.runCycle).toHaveBeenCalledTimes(2);
    await poller.close();
  });

  it('logs a recoverable cycle failure and waits before retrying', async () => {
    const monitoring = testCycle();
    monitoring.runCycle.mockRejectedValueOnce(new Error('synthetic failure'));
    const logger = pino({ level: 'silent' });
    const logError = vi.spyOn(logger, 'error');
    const poller = new PaperMarketMonitorPoller({
      intervalMs: 1_000,
      logger,
      cycle: monitoring.cycle,
    });

    poller.start();
    await flushMicrotasks();

    expect(monitoring.runCycle).toHaveBeenCalledOnce();
    expect(logError).toHaveBeenCalledWith(
      { errorCode: 'PAPER_MARKET_MONITOR_CYCLE_FAILED' },
      'PAPER market monitoring cycle failed',
    );

    await vi.advanceTimersByTimeAsync(999);
    expect(monitoring.runCycle).toHaveBeenCalledOnce();
    await vi.advanceTimersByTimeAsync(1);
    expect(monitoring.runCycle).toHaveBeenCalledTimes(2);
    await poller.close();
  });

  it('makes repeated start and close calls idempotent', async () => {
    const monitoring = testCycle();
    const poller = new PaperMarketMonitorPoller({
      intervalMs: 1_000,
      logger: pino({ level: 'silent' }),
      cycle: monitoring.cycle,
    });

    poller.start();
    poller.start();
    await flushMicrotasks();
    await Promise.all([poller.close(), poller.close()]);
    poller.start();
    await vi.advanceTimersByTimeAsync(5_000);

    expect(monitoring.runCycle).toHaveBeenCalledOnce();
    expect(monitoring.shutdown).toHaveBeenCalledOnce();
  });

  it('awaits an active cycle before releasing its leases', async () => {
    const activeCycle = deferred<PaperMarketMonitoringSummary>();
    const monitoring = testCycle(() => activeCycle.promise);
    const poller = new PaperMarketMonitorPoller({
      intervalMs: 1_000,
      logger: pino({ level: 'silent' }),
      cycle: monitoring.cycle,
    });
    let closed = false;

    poller.start();
    await flushMicrotasks();
    const closing = poller.close().then((): void => {
      closed = true;
    });
    await flushMicrotasks();

    expect(closed).toBe(false);
    expect(monitoring.shutdown).not.toHaveBeenCalled();

    activeCycle.resolve(summary);
    await closing;

    expect(monitoring.shutdown).toHaveBeenCalledOnce();
    expect(closed).toBe(true);
  });

  it('stops before another cycle when leadership is lost', async () => {
    let leadershipChecks = 0;
    const monitoring = testCycle();
    const poller = new PaperMarketMonitorPoller({
      intervalMs: 1_000,
      logger: pino({ level: 'silent' }),
      cycle: monitoring.cycle,
      beforeCycle: (): Promise<boolean> => {
        leadershipChecks += 1;

        return Promise.resolve(leadershipChecks === 1);
      },
    });

    poller.start();
    await flushMicrotasks();
    await vi.advanceTimersByTimeAsync(1_000);
    await poller.completed;

    expect(monitoring.runCycle).toHaveBeenCalledOnce();
    expect(monitoring.shutdown).toHaveBeenCalledOnce();
    expect(vi.getTimerCount()).toBe(0);
    await poller.close();
  });

  it('stops after a leadership check failure without a retry loop', async () => {
    const monitoring = testCycle();
    const logger = pino({ level: 'silent' });
    const logError = vi.spyOn(logger, 'error');
    const poller = new PaperMarketMonitorPoller({
      intervalMs: 1_000,
      logger,
      cycle: monitoring.cycle,
      beforeCycle: (): Promise<boolean> =>
        Promise.reject(new Error('synthetic leadership failure')),
    });

    poller.start();
    await poller.completed;
    await vi.advanceTimersByTimeAsync(5_000);

    expect(monitoring.runCycle).not.toHaveBeenCalled();
    expect(monitoring.shutdown).toHaveBeenCalledOnce();
    expect(logError).toHaveBeenCalledWith(
      { errorCode: 'PAPER_MARKET_MONITOR_LEADERSHIP_CHECK_FAILED' },
      'PAPER market monitoring leadership check failed',
    );
    await poller.close();
  });

  it('cancels an interval wait and cleans up exactly once', async () => {
    const monitoring = testCycle();
    const poller = new PaperMarketMonitorPoller({
      intervalMs: 300_000,
      logger: pino({ level: 'silent' }),
      cycle: monitoring.cycle,
    });

    poller.start();
    await flushMicrotasks();
    expect(vi.getTimerCount()).toBe(1);

    await poller.close();
    await vi.advanceTimersByTimeAsync(300_000);

    expect(vi.getTimerCount()).toBe(0);
    expect(monitoring.runCycle).toHaveBeenCalledOnce();
    expect(monitoring.shutdown).toHaveBeenCalledOnce();
  });

  it.each([0, 300_001, 1.5])('rejects unsafe poll interval %s', (intervalMs) => {
    const monitoring = testCycle();

    expect(() => new PaperMarketMonitorPoller({
      intervalMs,
      logger: pino({ level: 'silent' }),
      cycle: monitoring.cycle,
    })).toThrow(
      'PAPER market monitor poll interval must be between 1 and 300000 ms',
    );
  });
});
