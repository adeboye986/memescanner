import { promises as fs } from 'node:fs';

import pino from 'pino';
import { describe, expect, it, type Mock, vi } from 'vitest';

import { startWorker } from '../../apps/worker/src/runtime.js';
import { createDatabase } from '../../src/infrastructure/database/client.js';
import type { RuntimeHandle } from '../../src/infrastructure/runtime/process-lifecycle.js';
import type {
  PaperMarketMonitorRuntimeHandle,
  PaperMarketMonitorRuntimeOptions,
} from '../../src/workers/paper-market-monitor-runtime.js';
import type { PostgresWorkflowLeaderOptions } from '../../src/workers/postgres-workflow-leader.js';
import type {
  WorkflowPollerHandle,
  WorkflowPollerOptions,
} from '../../src/workers/workflow-poller.js';
import { createTestIdentity } from '../support/test-environment.js';

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

describe('PostgreSQL workflow worker runtime', () => {
  it('starts leadership-safe polling and destroys resources exactly once', async () => {
    const identity = createTestIdentity();
    const database = createDatabase(identity.config);
    const destroyDatabase = vi
      .spyOn(database, 'destroy')
      .mockResolvedValue(undefined);
    const closePoller = vi.fn((): Promise<void> => Promise.resolve());
    const poller: WorkflowPollerHandle = {
      close: closePoller,
      completed: Promise.resolve(),
    };
    const pollerStarter = vi.fn<
      (options: WorkflowPollerOptions) => WorkflowPollerHandle
    >((): WorkflowPollerHandle => poller);
    const paperMarketMonitorStarter = vi.fn<
      (options: PaperMarketMonitorRuntimeOptions) => PaperMarketMonitorRuntimeHandle
    >(() => {
      throw new Error('Disabled PAPER market monitoring must not be constructed');
    });
    const beforeCycle = (): Promise<boolean> => Promise.resolve(true);
    const closeLeadership = vi.fn(async (): Promise<void> => {
      await poller.close();
    });
    const leadership: RuntimeHandle = { close: closeLeadership };
    const leadershipStarter = vi.fn<
      (options: PostgresWorkflowLeaderOptions) => RuntimeHandle
    >((options): RuntimeHandle => {
      options.startPoller({ database, beforeCycle });
      return leadership;
    });

    const runtime = await startWorker(
      {
        config: identity.config,
        logger: pino({ level: 'silent' }),
        runMigrations: false,
      },
      {
        databaseFactory: () => database,
        leadershipStarter,
        pollerStarter,
        paperMarketMonitorStarter,
      },
    );

    expect(leadershipStarter).toHaveBeenCalledOnce();
    expect(leadershipStarter.mock.calls[0]?.[0]).toMatchObject({
      retryIntervalMs: identity.config.workflowLeaderRetryIntervalMs,
    });
    expect(pollerStarter).toHaveBeenCalledOnce();
    expect(pollerStarter.mock.calls[0]?.[0]).toMatchObject({
      intervalMs: identity.config.outboxPollIntervalMs,
      idleMaxIntervalMs: identity.config.workflowIdleMaxIntervalMs,
      beforeCycle,
    });
    expect(paperMarketMonitorStarter).not.toHaveBeenCalled();

    await Promise.all([runtime.close(), runtime.close()]);

    expect(closeLeadership).toHaveBeenCalledOnce();
    expect(closePoller).toHaveBeenCalledOnce();
    expect(destroyDatabase).toHaveBeenCalledOnce();
  });

  it('starts shadow monitoring once per leadership acquisition and isolates its completion', async () => {
    const identity = createTestIdentity(undefined, {
      PAPER_MARKET_MONITORING_ENABLED: 'true',
      PAPER_MARKET_MONITOR_CANARY_USER_IDS: '1',
      PAPER_MARKET_PROVIDER_TIMEOUT_MS: '3000',
      PAPER_MARKET_MONITOR_LEASE_DURATION_MS: '10000',
      SHUTDOWN_TIMEOUT_MS: '5000',
    });
    const database = createDatabase(identity.config);
    const destroyDatabase = vi
      .spyOn(database, 'destroy')
      .mockResolvedValue(undefined);
    const workflowCloses: Mock<WorkflowPollerHandle['close']>[] = [];
    const pollerStarter = vi.fn<
      (options: WorkflowPollerOptions) => WorkflowPollerHandle
    >((): WorkflowPollerHandle => {
      const completion = deferredVoid();
      const close = vi.fn((): Promise<void> => {
        completion.resolve();

        return Promise.resolve();
      });
      workflowCloses.push(close);

      return { close, completed: completion.promise };
    });
    const monitorCloses: Mock<PaperMarketMonitorRuntimeHandle['close']>[] = [];
    const paperMarketMonitorStarter = vi.fn<
      (options: PaperMarketMonitorRuntimeOptions) => PaperMarketMonitorRuntimeHandle
    >((): PaperMarketMonitorRuntimeHandle => {
      const close = vi.fn((): Promise<void> => Promise.resolve());
      monitorCloses.push(close);

      return { close, completed: Promise.resolve() };
    });
    let leaderOptions: PostgresWorkflowLeaderOptions | undefined;
    const leadershipClose = vi.fn((): Promise<void> => Promise.resolve());
    const leadershipStarter = vi.fn<
      (options: PostgresWorkflowLeaderOptions) => RuntimeHandle
    >((options): RuntimeHandle => {
      leaderOptions = options;

      return { close: leadershipClose };
    });
    const logger = pino({ level: 'silent' });
    const runtime = await startWorker(
      {
        config: identity.config,
        logger,
        runMigrations: false,
      },
      {
        databaseFactory: () => database,
        leadershipStarter,
        pollerStarter,
        paperMarketMonitorStarter,
      },
    );
    const beforeCycle = (): Promise<boolean> => Promise.resolve(true);

    expect(paperMarketMonitorStarter).not.toHaveBeenCalled();

    const first = leaderOptions?.startPoller({ database, beforeCycle });
    if (first === undefined) {
      expect.fail('Expected the first leadership acquisition to start pollers');
    }
    await flushMicrotasks();
    let firstCompleted = false;
    void first.completed.then((): void => {
      firstCompleted = true;
    });
    await flushMicrotasks();

    expect(paperMarketMonitorStarter).toHaveBeenCalledOnce();
    expect(paperMarketMonitorStarter).toHaveBeenLastCalledWith({
      config: identity.config,
      database,
      logger,
      beforeCycle,
    });
    expect(firstCompleted).toBe(false);

    await first.close();
    await flushMicrotasks();
    expect(firstCompleted).toBe(true);

    const second = leaderOptions?.startPoller({ database, beforeCycle });
    if (second === undefined) {
      expect.fail('Expected leadership reacquisition to start pollers');
    }
    await flushMicrotasks();

    expect(paperMarketMonitorStarter).toHaveBeenCalledTimes(2);

    await second.close();
    await runtime.close();

    expect(workflowCloses).toHaveLength(2);
    expect(workflowCloses.every((close) => close.mock.calls.length === 1)).toBe(true);
    expect(monitorCloses).toHaveLength(2);
    expect(monitorCloses.every((close) => close.mock.calls.length === 1)).toBe(true);
    expect(leadershipClose).toHaveBeenCalledOnce();
    expect(destroyDatabase).toHaveBeenCalledOnce();
  });

  it('keeps the Hostinger workflow path free of BullMQ producers and consumers', async () => {
    const sources = await Promise.all([
      fs.readFile('apps/hostinger/src/runtime.ts', 'utf8'),
      fs.readFile('apps/worker/src/runtime.ts', 'utf8'),
      fs.readFile('apps/scheduler/src/runtime.ts', 'utf8'),
      fs.readFile('src/workers/postgres-workflow-leader.ts', 'utf8'),
      fs.readFile('src/workers/workflow-poller.ts', 'utf8'),
    ]);
    const productionPath = sources.join('\n');

    expect(productionPath).not.toMatch(/from ['"]bullmq['"]/);
    expect(productionPath).not.toMatch(/new (?:Queue|Worker)\b/);
    expect(productionPath).not.toMatch(/\.add\(/);
  });
});
