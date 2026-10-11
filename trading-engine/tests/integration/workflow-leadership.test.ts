import type { Kysely } from 'kysely';
import pino from 'pino';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';

import {
  createDatabase,
  type Database,
} from '../../src/infrastructure/database/client.js';
import { paperMarketMonitoringCapabilitiesAvailable } from '../../src/workers/paper-market-monitor-runtime.js';
import {
  PostgresWorkflowLeadershipBackend,
  startPostgresWorkflowLeader,
} from '../../src/workers/postgres-workflow-leader.js';
import { startWorkflowPoller } from '../../src/workers/workflow-poller.js';
import {
  createTestIdentity,
  startTestEnvironment,
  stopTestEnvironment,
  type TestEnvironment,
} from '../support/test-environment.js';

async function waitForCondition(
  condition: () => boolean,
  description: string,
  timeoutMs = 5_000,
): Promise<void> {
  const deadline = Date.now() + timeoutMs;

  while (!condition()) {
    if (Date.now() >= deadline) {
      throw new Error(`Timed out waiting for ${description}`);
    }

    await new Promise((resolve) => setTimeout(resolve, 10));
  }
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

describe('PostgreSQL workflow leadership session', () => {
  let environment: TestEnvironment;
  let firstDatabase: Kysely<Database>;
  let secondDatabase: Kysely<Database>;

  beforeAll(async () => {
    environment = await startTestEnvironment();
    const config = createTestIdentity(environment).config;
    firstDatabase = createDatabase(config);
    secondDatabase = createDatabase(config);
  });

  afterAll(async () => {
    await Promise.all([firstDatabase.destroy(), secondDatabase.destroy()]);
    await stopTestEnvironment(environment);
  });

  it('keeps one dedicated-session leader and transfers after release', async () => {
    const firstBackend = new PostgresWorkflowLeadershipBackend(firstDatabase);
    const secondBackend = new PostgresWorkflowLeadershipBackend(secondDatabase);
    const leaderReady = deferredVoid();
    const releaseLeader = deferredVoid();
    const leader = firstBackend.withSession(async (session): Promise<void> => {
      expect(await session.tryAcquire()).toBe(true);
      expect(await session.isHeld()).toBe(true);
      leaderReady.resolve();
      await releaseLeader.promise;
      expect(await session.release()).toBe(true);
    });

    await leaderReady.promise;
    await secondBackend.withSession(async (session): Promise<void> => {
      expect(await session.tryAcquire()).toBe(false);
      expect(await session.isHeld()).toBe(false);
    });

    releaseLeader.resolve();
    await leader;

    await secondBackend.withSession(async (session): Promise<void> => {
      expect(await session.tryAcquire()).toBe(true);
      expect(await session.isHeld()).toBe(true);
      expect(await session.release()).toBe(true);
    });
  });

  it('runs one of two runtime instances and transfers after graceful release', async () => {
    let firstCycles = 0;
    let secondCycles = 0;
    const logger = pino({ level: 'silent' });
    const startRuntime = (
      backend: PostgresWorkflowLeadershipBackend,
      increment: () => void,
    ): ReturnType<typeof startPostgresWorkflowLeader> => startPostgresWorkflowLeader({
      backend,
      retryIntervalMs: 25,
      logger,
      startPoller: ({ beforeCycle }) => startWorkflowPoller({
        intervalMs: 25,
        idleMaxIntervalMs: 25,
        logger,
        beforeCycle,
        outboxDispatcher: {
          dispatchBatch: (): Promise<{ readonly didWork: boolean }> => {
            increment();
            return Promise.resolve({ didWork: false });
          },
          shutdown: (): Promise<void> => Promise.resolve(),
        },
        evaluationDispatcher: {
          dispatchBatch: (): Promise<{ readonly didWork: boolean }> =>
            Promise.resolve({ didWork: false }),
          shutdown: (): Promise<void> => Promise.resolve(),
        },
      }),
    });
    const first = startRuntime(
      new PostgresWorkflowLeadershipBackend(firstDatabase),
      () => { firstCycles += 1; },
    );

    await waitForCondition(() => firstCycles > 0, 'first runtime leadership');
    const second = startRuntime(
      new PostgresWorkflowLeadershipBackend(secondDatabase),
      () => { secondCycles += 1; },
    );
    await new Promise((resolve) => setTimeout(resolve, 100));

    expect(firstCycles).toBeGreaterThan(0);
    expect(secondCycles).toBe(0);

    await first.close();
    await waitForCondition(() => secondCycles > 0, 'standby runtime takeover');
    await second.close();
  });

  it('recognizes the complete migrated shadow monitoring schema', async () => {
    await expect(
      paperMarketMonitoringCapabilitiesAvailable(firstDatabase),
    ).resolves.toBe(true);
  });
});
