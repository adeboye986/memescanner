import type { Kysely } from 'kysely';
import pino from 'pino';
import { describe, expect, it, vi } from 'vitest';

import type { Database } from '../../src/infrastructure/database/client.js';
import type { PaperMarketMonitoringCycleRunner } from '../../src/workers/paper-market-monitor-poller.js';
import {
  startPaperMarketMonitorRuntime,
  type PaperMarketMonitorRuntimeDependencies,
} from '../../src/workers/paper-market-monitor-runtime.js';
import { createTestIdentity } from '../support/test-environment.js';

const database = {} as Kysely<Database>;

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
  for (let iteration = 0; iteration < 8; iteration += 1) {
    await Promise.resolve();
  }
}

function enabledConfig(
  overrides: Readonly<Record<string, string>> = {},
): ReturnType<typeof createTestIdentity>['config'] {
  return createTestIdentity(undefined, {
    PAPER_MARKET_MONITORING_ENABLED: 'true',
    PAPER_MARKET_MONITOR_CANARY_USER_IDS: '1',
    PAPER_MARKET_PROVIDER_TIMEOUT_MS: '3000',
    PAPER_MARKET_MONITOR_LEASE_DURATION_MS: '10000',
    SHUTDOWN_TIMEOUT_MS: '5000',
    ...overrides,
  }).config;
}

describe('leader-controlled PAPER market monitor runtime', () => {
  it('does not inspect schema or construct monitoring while disabled', async () => {
    const capabilityChecker = vi.fn(() => Promise.resolve(true));
    const cycleFactory = vi.fn();
    const pollerFactory = vi.fn();
    const runtime = startPaperMarketMonitorRuntime(
      {
        config: createTestIdentity().config,
        database,
        logger: pino({ level: 'silent' }),
        beforeCycle: (): Promise<boolean> => Promise.resolve(true),
      },
      { capabilityChecker, cycleFactory, pollerFactory },
    );

    await runtime.completed;
    await runtime.close();

    expect(capabilityChecker).not.toHaveBeenCalled();
    expect(cycleFactory).not.toHaveBeenCalled();
    expect(pollerFactory).not.toHaveBeenCalled();
  });

  it('starts monitoring only after required schema capabilities are available', async () => {
    const completion = deferredVoid();
    const start = vi.fn();
    const close = vi.fn((): Promise<void> => {
      completion.resolve();

      return Promise.resolve();
    });
    const cycle: PaperMarketMonitoringCycleRunner = {
      runCycle: vi.fn(),
      shutdown: vi.fn(),
    };
    const capabilityChecker = vi.fn<
      NonNullable<PaperMarketMonitorRuntimeDependencies['capabilityChecker']>
    >(() => Promise.resolve(true));
    const cycleFactory = vi.fn<
      NonNullable<PaperMarketMonitorRuntimeDependencies['cycleFactory']>
    >(() => cycle);
    const pollerFactory = vi.fn<
      NonNullable<PaperMarketMonitorRuntimeDependencies['pollerFactory']>
    >(() => ({
      start,
      close,
      completed: completion.promise,
    }));
    const beforeCycle = (): Promise<boolean> => Promise.resolve(true);
    const config = enabledConfig();
    const logger = pino({ level: 'silent' });
    const runtime = startPaperMarketMonitorRuntime(
      {
        config,
        database,
        logger,
        beforeCycle,
      },
      { capabilityChecker, cycleFactory, pollerFactory },
    );

    await flushMicrotasks();

    expect(capabilityChecker).toHaveBeenCalledWith(database);
    expect(cycleFactory).toHaveBeenCalledWith({
      config,
      database,
      logger,
      policy: {
        enabled: true,
        eligibleControlPlaneUserIds: config.paperMarketMonitorCanaryUserIds,
        intervalMs: config.paperMarketMonitorIntervalMs,
        batchSize: config.paperMarketMonitorBatchSize,
        requestBudget: config.paperMarketMonitorRequestBudget,
        maximumBackoffMs: config.paperMarketMonitorMaximumBackoffMs,
        leaseDurationMs: config.paperMarketMonitorLeaseDurationMs,
      },
    });
    expect(pollerFactory).toHaveBeenCalledWith({
      intervalMs: config.paperMarketMonitorIntervalMs,
      logger,
      beforeCycle,
      cycle,
    });
    expect(start).toHaveBeenCalledOnce();

    await runtime.close();
    expect(close).toHaveBeenCalledOnce();
  });

  it('does not construct monitoring when migrations are unavailable', async () => {
    const cycleFactory = vi.fn();
    const pollerFactory = vi.fn();
    const logger = pino({ level: 'silent' });
    const logError = vi.spyOn(logger, 'error');
    const runtime = startPaperMarketMonitorRuntime(
      {
        config: enabledConfig(),
        database,
        logger,
        beforeCycle: (): Promise<boolean> => Promise.resolve(true),
      },
      {
        capabilityChecker: (): Promise<boolean> => Promise.resolve(false),
        cycleFactory,
        pollerFactory,
      },
    );

    await runtime.completed;
    await runtime.close();

    expect(cycleFactory).not.toHaveBeenCalled();
    expect(pollerFactory).not.toHaveBeenCalled();
    expect(logError).toHaveBeenCalledWith(
      { errorCode: 'PAPER_MARKET_MONITOR_SCHEMA_UNAVAILABLE' },
      'PAPER market monitoring was not started',
    );
  });

  it('isolates a schema capability check failure from the owning workflow', async () => {
    const logger = pino({ level: 'silent' });
    const logError = vi.spyOn(logger, 'error');
    const runtime = startPaperMarketMonitorRuntime(
      {
        config: enabledConfig(),
        database,
        logger,
        beforeCycle: (): Promise<boolean> => Promise.resolve(true),
      },
      {
        capabilityChecker: (): Promise<boolean> =>
          Promise.reject(new Error('synthetic schema failure')),
      },
    );

    await expect(runtime.completed).resolves.toBeUndefined();
    await expect(runtime.close()).resolves.toBeUndefined();
    expect(logError).toHaveBeenCalledWith(
      { errorCode: 'PAPER_MARKET_MONITOR_SCHEMA_CHECK_FAILED' },
      'PAPER market monitoring was not started',
    );
  });

  it('isolates an unexpected monitor completion failure', async () => {
    const logger = pino({ level: 'silent' });
    const logError = vi.spyOn(logger, 'error');
    const runtime = startPaperMarketMonitorRuntime(
      {
        config: enabledConfig(),
        database,
        logger,
        beforeCycle: (): Promise<boolean> => Promise.resolve(true),
      },
      {
        capabilityChecker: (): Promise<boolean> => Promise.resolve(true),
        cycleFactory: (): PaperMarketMonitoringCycleRunner => ({
          runCycle: vi.fn(),
          shutdown: vi.fn(),
        }),
        pollerFactory: () => ({
          start: vi.fn(),
          close: vi.fn((): Promise<void> => Promise.resolve()),
          completed: Promise.reject(new Error('synthetic monitor failure')),
        }),
      },
    );

    await expect(runtime.completed).resolves.toBeUndefined();

    expect(logError).toHaveBeenCalledWith(
      { errorCode: 'PAPER_MARKET_MONITOR_STOPPED_UNEXPECTEDLY' },
      'PAPER market monitoring stopped unexpectedly',
    );
    await runtime.close();
  });

  it('refuses an operational budget that can exceed lease or shutdown limits', async () => {
    const capabilityChecker = vi.fn(() => Promise.resolve(true));
    const logger = pino({ level: 'silent' });
    const logError = vi.spyOn(logger, 'error');
    const runtime = startPaperMarketMonitorRuntime(
      {
        config: enabledConfig({
          PAPER_MARKET_MONITOR_BATCH_SIZE: '60',
          PAPER_MARKET_MONITOR_REQUEST_BUDGET: '2',
          PAPER_MARKET_PROVIDER_TIMEOUT_MS: '3000',
          PAPER_MARKET_MONITOR_LEASE_DURATION_MS: '10000',
          SHUTDOWN_TIMEOUT_MS: '5000',
        }),
        database,
        logger,
        beforeCycle: (): Promise<boolean> => Promise.resolve(true),
      },
      { capabilityChecker },
    );

    await runtime.completed;
    await runtime.close();

    expect(capabilityChecker).not.toHaveBeenCalled();
    expect(logError).toHaveBeenCalledWith(
      { errorCode: 'PAPER_MARKET_MONITOR_OPERATIONAL_BUDGET_UNSAFE' },
      'PAPER market monitoring was not started',
    );
  });

  it('waits for active monitoring cleanup during graceful shutdown', async () => {
    const activeClose = deferredVoid();
    const close = vi.fn(() => activeClose.promise);
    const pollerFactory = vi.fn<
      NonNullable<PaperMarketMonitorRuntimeDependencies['pollerFactory']>
    >(() => ({
      start: vi.fn(),
      close,
      completed: activeClose.promise,
    }));
    const runtime = startPaperMarketMonitorRuntime(
      {
        config: enabledConfig(),
        database,
        logger: pino({ level: 'silent' }),
        beforeCycle: (): Promise<boolean> => Promise.resolve(true),
      },
      {
        capabilityChecker: (): Promise<boolean> => Promise.resolve(true),
        cycleFactory: (): PaperMarketMonitoringCycleRunner => ({
          runCycle: vi.fn(),
          shutdown: vi.fn(),
        }),
        pollerFactory,
      },
    );
    let closed = false;

    await flushMicrotasks();
    const closing = runtime.close().then((): void => {
      closed = true;
    });
    await flushMicrotasks();

    expect(closed).toBe(false);
    expect(close).toHaveBeenCalledOnce();

    activeClose.resolve();
    await closing;
    expect(closed).toBe(true);
  });
});
