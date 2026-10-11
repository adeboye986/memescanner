import { sql, type Kysely } from 'kysely';
import type { Logger } from 'pino';

import {
  databaseConnectionLossSignal,
  type Database,
  type DatabaseConnectionLossSignal,
} from '../infrastructure/database/client.js';
import {
  closeInOrder,
  idempotentClose,
  type RuntimeHandle,
} from '../infrastructure/runtime/process-lifecycle.js';
import type { WorkflowPollerHandle } from './workflow-poller.js';

const workflowLockNamespace = 1_297_302_527;
const workflowLockKey = 1;

export interface WorkflowLeadershipSession {
  readonly database: Kysely<Database>;
  readonly connectionLost: Promise<void>;
  isConnectionLost(): boolean;
  tryAcquire(): Promise<boolean>;
  isHeld(): Promise<boolean>;
  release(): Promise<boolean>;
}

export interface WorkflowLeadershipBackend {
  withSession(
    callback: (session: WorkflowLeadershipSession) => Promise<void>,
  ): Promise<void>;
}

export interface WorkflowLeaderPollerOptions {
  readonly database: Kysely<Database>;
  readonly beforeCycle: () => Promise<boolean>;
}

export interface PostgresWorkflowLeaderOptions {
  readonly backend: WorkflowLeadershipBackend;
  readonly retryIntervalMs: number;
  readonly logger: Logger;
  readonly startPoller: (
    options: WorkflowLeaderPollerOptions,
  ) => WorkflowPollerHandle;
}

export class PostgresWorkflowLeadershipBackend implements WorkflowLeadershipBackend {
  public constructor(private readonly database: Kysely<Database>) {}

  public async withSession(
    callback: (session: WorkflowLeadershipSession) => Promise<void>,
  ): Promise<void> {
    await this.database.connection().execute(async (connection) => {
      const backend = await sql<{ readonly pid: number }>`
        select pg_backend_pid()::int as pid
      `.execute(connection);
      const backendPid = backend.rows[0]?.pid;
      const connectionLoss = backendPid === undefined
        ? undefined
        : databaseConnectionLossSignal(this.database, backendPid);

      if (connectionLoss === undefined) {
        throw new Error('PostgreSQL leadership connection is not monitored');
      }

      await callback(new PostgresWorkflowLeadershipSession(
        connection,
        connectionLoss,
      ));
    });
  }
}

class PostgresWorkflowLeadershipSession implements WorkflowLeadershipSession {
  public readonly connectionLost: Promise<void>;

  public constructor(
    public readonly database: Kysely<Database>,
    private readonly connectionLoss: DatabaseConnectionLossSignal,
  ) {
    this.connectionLost = connectionLoss.lost;
  }

  public isConnectionLost(): boolean {
    return this.connectionLoss.isLost();
  }

  public async tryAcquire(): Promise<boolean> {
    if (this.isConnectionLost()) {
      return false;
    }

    const result = await sql<{ readonly acquired: boolean }>`
      select pg_try_advisory_lock(
        ${workflowLockNamespace},
        ${workflowLockKey}
      ) as acquired
    `.execute(this.database);

    return result.rows[0]?.acquired === true;
  }

  public async isHeld(): Promise<boolean> {
    if (this.isConnectionLost()) {
      return false;
    }

    const result = await sql<{ readonly held: boolean }>`
      select exists (
        select 1
        from pg_locks
        where locktype = 'advisory'
          and pid = pg_backend_pid()
          and classid = ${workflowLockNamespace}::oid
          and objid = ${workflowLockKey}::oid
          and objsubid = 2
          and granted
      ) as held
    `.execute(this.database);

    return result.rows[0]?.held === true;
  }

  public async release(): Promise<boolean> {
    if (this.isConnectionLost()) {
      return false;
    }

    const result = await sql<{ readonly released: boolean }>`
      select pg_advisory_unlock(
        ${workflowLockNamespace},
        ${workflowLockKey}
      ) as released
    `.execute(this.database);

    return result.rows[0]?.released === true;
  }
}

export function startPostgresWorkflowLeader(
  options: PostgresWorkflowLeaderOptions,
): RuntimeHandle {
  let stopping = false;
  let activePoller: WorkflowPollerHandle | undefined;
  let timer: NodeJS.Timeout | undefined;
  let releaseWait: (() => void) | undefined;

  const waitBeforeRetry = (): Promise<void> => new Promise((resolve) => {
    const complete = (): void => {
      if (timer !== undefined) {
        clearTimeout(timer);
      }

      timer = undefined;
      releaseWait = undefined;
      resolve();
    };

    timer = setTimeout(complete, options.retryIntervalMs);
    releaseWait = complete;
  });

  const runLeadershipSession = async (
    session: WorkflowLeadershipSession,
  ): Promise<void> => {
    if (!await session.tryAcquire()) {
      return;
    }

    if (stopping || session.isConnectionLost()) {
      await session.release();
      return;
    }

    options.logger.info('PostgreSQL workflow leadership acquired');
    let poller: WorkflowPollerHandle | undefined;

    try {
      poller = options.startPoller({
        database: session.database,
        beforeCycle: (): Promise<boolean> => {
          if (stopping) {
            return Promise.resolve(false);
          }

          return session.isHeld();
        },
      });
      activePoller = poller;
      const completion = await Promise.race([
        poller.completed.then(() => 'poller' as const),
        session.connectionLost.then(() => 'connection' as const),
      ]);

      if (completion === 'connection') {
        options.logger.warn(
          'PostgreSQL workflow leadership connection was lost',
        );
      }
    } finally {
      const currentPoller = poller;

      try {
        await closeInOrder('PostgreSQL workflow leadership cleanup', [
          ...(currentPoller === undefined
            ? []
            : [{
                name: 'workflow poller',
                close: (): Promise<void> => currentPoller.close(),
              }]),
          {
            name: 'workflow advisory lock',
            close: async (): Promise<void> => {
              const released = await session.release();

              if (!released) {
                options.logger.warn(
                  'PostgreSQL workflow leadership was already lost',
                );
              }
            },
          },
        ]);
      } finally {
        activePoller = undefined;
      }
    }
  };

  const isRunning = (): boolean => !stopping;
  const run = async (): Promise<void> => {
    while (isRunning()) {
      try {
        await options.backend.withSession(runLeadershipSession);
      } catch (error) {
        options.logger.error(
          { err: error },
          'PostgreSQL workflow leadership attempt failed',
        );
      }

      if (isRunning()) {
        await waitBeforeRetry();
      }
    }
  };

  const runPromise = run();
  const close = idempotentClose(async (): Promise<void> => {
    stopping = true;
    releaseWait?.();
    const currentPoller = activePoller;

    await closeInOrder('PostgreSQL workflow leader shutdown', [
      ...(currentPoller === undefined
        ? []
        : [{
            name: 'active workflow poller',
            close: (): Promise<void> => currentPoller.close(),
          }]),
      {
        name: 'leadership loop',
        close: (): Promise<void> => runPromise,
      },
    ]);
  });

  return { close };
}
