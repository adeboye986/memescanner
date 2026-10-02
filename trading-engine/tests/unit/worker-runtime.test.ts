import { promises as fs } from 'node:fs';

import pino from 'pino';
import { describe, expect, it, vi } from 'vitest';

import { startWorker } from '../../apps/worker/src/runtime.js';
import { createDatabase } from '../../src/infrastructure/database/client.js';
import type { RuntimeHandle } from '../../src/infrastructure/runtime/process-lifecycle.js';
import type { WorkflowPollerOptions } from '../../src/workers/workflow-poller.js';
import { createTestIdentity } from '../support/test-environment.js';

describe('PostgreSQL workflow worker runtime', () => {
  it('starts the poller and destroys resources exactly once', async () => {
    const identity = createTestIdentity();
    const database = createDatabase(identity.config);
    const destroyDatabase = vi
      .spyOn(database, 'destroy')
      .mockResolvedValue(undefined);
    const closePoller = vi.fn((): Promise<void> => Promise.resolve());
    const poller: RuntimeHandle = { close: closePoller };
    const pollerStarter = vi.fn<
      (options: WorkflowPollerOptions) => RuntimeHandle
    >(
      (): RuntimeHandle => poller,
    );

    const runtime = await startWorker(
      {
        config: identity.config,
        logger: pino({ level: 'silent' }),
        runMigrations: false,
      },
      {
        databaseFactory: () => database,
        pollerStarter,
      },
    );

    expect(pollerStarter).toHaveBeenCalledOnce();
    expect(pollerStarter.mock.calls[0]?.[0]).toMatchObject({
      intervalMs: identity.config.outboxPollIntervalMs,
    });

    await Promise.all([runtime.close(), runtime.close()]);

    expect(closePoller).toHaveBeenCalledOnce();
    expect(destroyDatabase).toHaveBeenCalledOnce();
  });

  it('keeps the Hostinger workflow path free of BullMQ producers and consumers', async () => {
    const sources = await Promise.all([
      fs.readFile('apps/hostinger/src/runtime.ts', 'utf8'),
      fs.readFile('apps/worker/src/runtime.ts', 'utf8'),
      fs.readFile('apps/scheduler/src/runtime.ts', 'utf8'),
      fs.readFile('src/workers/workflow-poller.ts', 'utf8'),
    ]);
    const productionPath = sources.join('\n');

    expect(productionPath).not.toMatch(/from ['"]bullmq['"]/);
    expect(productionPath).not.toMatch(/new (?:Queue|Worker)\b/);
    expect(productionPath).not.toMatch(/\.add\(/);
  });
});
