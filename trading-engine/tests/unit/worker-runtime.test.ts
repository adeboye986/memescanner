import { promises as fs } from 'node:fs';

import pino from 'pino';
import { describe, expect, it, vi } from 'vitest';

import { startWorker } from '../../apps/worker/src/runtime.js';
import { createDatabase } from '../../src/infrastructure/database/client.js';
import type { RuntimeHandle } from '../../src/infrastructure/runtime/process-lifecycle.js';
import type { PostgresWorkflowLeaderOptions } from '../../src/workers/postgres-workflow-leader.js';
import type {
  WorkflowPollerHandle,
  WorkflowPollerOptions,
} from '../../src/workers/workflow-poller.js';
import { createTestIdentity } from '../support/test-environment.js';

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

    await Promise.all([runtime.close(), runtime.close()]);

    expect(closeLeadership).toHaveBeenCalledOnce();
    expect(closePoller).toHaveBeenCalledOnce();
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
