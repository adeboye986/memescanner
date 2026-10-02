import type { Kysely } from 'kysely';
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

import type { Database } from '../../src/infrastructure/database/client.js';
import {
  startPostgresWorkflowLeader,
  type WorkflowLeadershipBackend,
  type WorkflowLeadershipSession,
} from '../../src/workers/postgres-workflow-leader.js';
import {
  startWorkflowPoller,
  type WorkflowDispatcher,
  type WorkflowPollerHandle,
} from '../../src/workers/workflow-poller.js';

const database = {} as Kysely<Database>;

class SharedLeadership {
  public attempts = 0;
  public failNextAttempt = false;
  private owner: symbol | undefined;

  public backend(identity: symbol): WorkflowLeadershipBackend {
    return {
      withSession: async (callback): Promise<void> => {
        const session: WorkflowLeadershipSession = {
          database,
          tryAcquire: (): Promise<boolean> => {
            this.attempts += 1;

            if (this.failNextAttempt) {
              this.failNextAttempt = false;
              return Promise.reject(new Error('synthetic acquisition failure'));
            }

            if (this.owner === undefined) {
              this.owner = identity;
              return Promise.resolve(true);
            }

            return Promise.resolve(this.owner === identity);
          },
          isHeld: (): Promise<boolean> => Promise.resolve(this.owner === identity),
          release: (): Promise<boolean> => {
            if (this.owner !== identity) {
              return Promise.resolve(false);
            }

            this.owner = undefined;
            return Promise.resolve(true);
          },
        };

        await callback(session);
      },
    };
  }

  public loseLeadership(): void {
    this.owner = undefined;
  }
}

interface RuntimeFixture {
  readonly runtime: ReturnType<typeof startPostgresWorkflowLeader>;
  readonly outbox: Mock<WorkflowDispatcher['dispatchBatch']>;
  readonly evaluation: Mock<WorkflowDispatcher['dispatchBatch']>;
  readonly outboxShutdown: Mock<WorkflowDispatcher['shutdown']>;
  readonly evaluationShutdown: Mock<WorkflowDispatcher['shutdown']>;
}

function startRuntime(
  leadership: SharedLeadership,
  identity: symbol,
  retryIntervalMs = 1_000,
): RuntimeFixture {
  const outbox = vi.fn<WorkflowDispatcher['dispatchBatch']>(() => Promise.resolve());
  const evaluation = vi.fn<WorkflowDispatcher['dispatchBatch']>(() => Promise.resolve());
  const outboxShutdown = vi.fn<WorkflowDispatcher['shutdown']>(() => Promise.resolve());
  const evaluationShutdown = vi.fn<WorkflowDispatcher['shutdown']>(() => Promise.resolve());
  const runtime = startPostgresWorkflowLeader({
    backend: leadership.backend(identity),
    retryIntervalMs,
    logger: pino({ level: 'silent' }),
    startPoller: ({ beforeCycle }): WorkflowPollerHandle => startWorkflowPoller({
      intervalMs: 100,
      logger: pino({ level: 'silent' }),
      beforeCycle,
      outboxDispatcher: { dispatchBatch: outbox, shutdown: outboxShutdown },
      evaluationDispatcher: { dispatchBatch: evaluation, shutdown: evaluationShutdown },
    }),
  });

  return {
    runtime,
    outbox,
    evaluation,
    outboxShutdown,
    evaluationShutdown,
  };
}

async function flushMicrotasks(): Promise<void> {
  for (let iteration = 0; iteration < 8; iteration += 1) {
    await Promise.resolve();
  }
}

describe('PostgreSQL workflow leadership', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('allows only one of two runtimes to execute workflow dispatchers', async () => {
    const leadership = new SharedLeadership();
    const leader = startRuntime(leadership, Symbol('leader'));
    await flushMicrotasks();
    const standby = startRuntime(leadership, Symbol('standby'));

    await flushMicrotasks();

    expect(leader.outbox).toHaveBeenCalledOnce();
    expect(leader.evaluation).toHaveBeenCalledOnce();
    expect(standby.outbox).not.toHaveBeenCalled();
    expect(standby.evaluation).not.toHaveBeenCalled();

    await Promise.all([leader.runtime.close(), standby.runtime.close()]);
  });

  it('transfers leadership to a standby after leader shutdown', async () => {
    const leadership = new SharedLeadership();
    const leader = startRuntime(leadership, Symbol('leader'));
    await flushMicrotasks();
    const standby = startRuntime(leadership, Symbol('standby'));
    await flushMicrotasks();

    await leader.runtime.close();
    await vi.advanceTimersByTimeAsync(1_000);
    await flushMicrotasks();

    expect(standby.outbox).toHaveBeenCalledOnce();
    expect(standby.evaluation).toHaveBeenCalledOnce();

    await standby.runtime.close();
  });

  it('stops polling after connection leadership is lost', async () => {
    const leadership = new SharedLeadership();
    const leader = startRuntime(leadership, Symbol('leader'));
    await flushMicrotasks();

    leadership.loseLeadership();
    await vi.advanceTimersByTimeAsync(100);
    await flushMicrotasks();
    await vi.advanceTimersByTimeAsync(500);

    expect(leader.outbox).toHaveBeenCalledOnce();
    expect(leader.evaluation).toHaveBeenCalledOnce();
    expect(leader.outboxShutdown).toHaveBeenCalledOnce();
    expect(leader.evaluationShutdown).toHaveBeenCalledOnce();

    await leader.runtime.close();
  });

  it('waits the configured interval after an acquisition failure', async () => {
    const leadership = new SharedLeadership();
    leadership.failNextAttempt = true;
    const runtime = startRuntime(leadership, Symbol('runtime'));
    await flushMicrotasks();

    expect(leadership.attempts).toBe(1);
    await vi.advanceTimersByTimeAsync(999);
    expect(leadership.attempts).toBe(1);

    await vi.advanceTimersByTimeAsync(1);
    await flushMicrotasks();

    expect(leadership.attempts).toBe(2);
    expect(runtime.outbox).toHaveBeenCalledOnce();

    await runtime.runtime.close();
  });
});
