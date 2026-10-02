import pino from 'pino';
import { describe, expect, it, vi } from 'vitest';

import { startScheduler } from '../../apps/scheduler/src/runtime.js';
import type { StartWorkerOptions } from '../../apps/worker/src/runtime.js';
import type { RuntimeHandle } from '../../src/infrastructure/runtime/process-lifecycle.js';
import { createTestIdentity } from '../support/test-environment.js';

describe('scheduler runtime', () => {
  it('starts PostgreSQL workflow processing without child migrations', async () => {
    const runtime: RuntimeHandle = {
      close: vi.fn((): Promise<void> => Promise.resolve()),
    };
    const workflowStarter = vi.fn<
      (options: StartWorkerOptions) => Promise<RuntimeHandle>
    >(
      (): Promise<RuntimeHandle> => Promise.resolve(runtime),
    );
    const identity = createTestIdentity();
    const logger = pino({ level: 'silent' });

    const started = await startScheduler({
      config: identity.config,
      logger,
      workflowStarter,
    });

    expect(started).toBe(runtime);
    expect(workflowStarter).toHaveBeenCalledOnce();
    expect(workflowStarter).toHaveBeenCalledWith({
      config: identity.config,
      logger,
      runMigrations: false,
    });
  });
});
