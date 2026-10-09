import type { Kysely } from 'kysely';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';

import {
  createDatabase,
  type Database,
} from '../../src/infrastructure/database/client.js';
import { paperMarketMonitoringCapabilitiesAvailable } from '../../src/workers/paper-market-monitor-runtime.js';
import { PostgresWorkflowLeadershipBackend } from '../../src/workers/postgres-workflow-leader.js';
import {
  createTestIdentity,
  startTestEnvironment,
  stopTestEnvironment,
  type TestEnvironment,
} from '../support/test-environment.js';

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

  it('recognizes the complete migrated shadow monitoring schema', async () => {
    await expect(
      paperMarketMonitoringCapabilitiesAvailable(firstDatabase),
    ).resolves.toBe(true);
  });
});
