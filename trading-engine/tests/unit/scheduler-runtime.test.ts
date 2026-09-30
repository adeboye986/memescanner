import { describe, expect, it, vi } from 'vitest';

import {
  scheduleWorkflows,
  type WorkflowQueue,
} from '../../apps/scheduler/src/runtime.js';
import {
  OPPORTUNITY_EVALUATION_JOB,
  OUTBOX_DISPATCH_JOB,
} from '../../src/infrastructure/queue/names.js';

describe('scheduler runtime', () => {
  it('preserves deterministic workflow jobs and retry retention options', async () => {
    const add = vi.fn<WorkflowQueue['add']>().mockResolvedValue(undefined);
    const now = new Date('2026-09-30T12:00:00.500Z');

    await scheduleWorkflows({ add }, 1_000, now);

    expect(add).toHaveBeenCalledTimes(2);
    expect(add).toHaveBeenNthCalledWith(
      1,
      OUTBOX_DISPATCH_JOB,
      { scheduledAt: '2026-09-30T12:00:00.500Z' },
      {
        removeOnComplete: { age: 3_600, count: 1_000 },
        removeOnFail: { age: 86_400, count: 1_000 },
        attempts: 3,
        backoff: { type: 'exponential', delay: 500 },
        jobId: 'outbox-dispatch-1790769600',
      },
    );
    expect(add).toHaveBeenNthCalledWith(
      2,
      OPPORTUNITY_EVALUATION_JOB,
      { scheduledAt: '2026-09-30T12:00:00.500Z' },
      {
        removeOnComplete: { age: 3_600, count: 1_000 },
        removeOnFail: { age: 86_400, count: 1_000 },
        attempts: 3,
        backoff: { type: 'exponential', delay: 500 },
        jobId: 'opportunity-evaluation-1790769600',
      },
    );
  });
});
