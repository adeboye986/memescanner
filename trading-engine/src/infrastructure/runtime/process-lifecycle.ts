import { existsSync } from 'node:fs';
import { loadEnvFile } from 'node:process';

import type { Logger } from 'pino';

export interface RuntimeHandle {
  close(): Promise<void>;
}

export interface CloseStep {
  readonly name: string;
  readonly close: () => Promise<void>;
}

export function loadLocalEnvironment(): void {
  if (existsSync('.env')) {
    loadEnvFile('.env');
  }
}

export function idempotentClose(close: () => Promise<void>): () => Promise<void> {
  let closePromise: Promise<void> | undefined;

  return (): Promise<void> => {
    closePromise ??= close();

    return closePromise;
  };
}

export async function closeInOrder(
  description: string,
  steps: readonly CloseStep[],
): Promise<void> {
  const errors: unknown[] = [];

  for (const step of steps) {
    try {
      await step.close();
    } catch (error) {
      errors.push(
        new Error(`${step.name} close failed`, {
          cause: error,
        }),
      );
    }
  }

  if (errors.length > 0) {
    throw new AggregateError(errors, `${description} failed`);
  }
}

export interface ManagedProcessOptions {
  readonly name: string;
  readonly shutdownTimeoutMs: number;
  readonly logger: Logger;
  readonly start: () => Promise<RuntimeHandle>;
  readonly finalize?: () => Promise<void>;
}

export async function runManagedProcess(
  options: ManagedProcessOptions,
): Promise<void> {
  let runtime: RuntimeHandle;

  try {
    runtime = await options.start();
  } catch (error) {
    options.logger.fatal({ err: error }, `${options.name} startup failed`);

    try {
      await options.finalize?.();
    } catch (finalizeError) {
      options.logger.error(
        { err: finalizeError },
        `${options.name} startup cleanup failed`,
      );
    }

    process.exitCode = 1;
    return;
  }

  let shutdownPromise: Promise<void> | undefined;
  const finalize = options.finalize;
  const shutdown = (signal: string): Promise<void> => {
    shutdownPromise ??= (async (): Promise<void> => {
      options.logger.info({ signal }, `${options.name} shutdown started`);
      const deadline = setTimeout(() => {
        options.logger.fatal(
          { signal },
          `${options.name} shutdown deadline exceeded`,
        );
        process.exit(1);
      }, options.shutdownTimeoutMs);
      deadline.unref();

      try {
        await closeInOrder(`${options.name} shutdown`, [
          { name: 'runtime', close: (): Promise<void> => runtime.close() },
          ...(finalize === undefined
            ? []
            : [{ name: 'finalizer', close: finalize }]),
        ]);
        options.logger.info({ signal }, `${options.name} shutdown completed`);
      } catch (error) {
        options.logger.error(
          { err: error, signal },
          `${options.name} shutdown failed`,
        );
        process.exitCode = 1;
      } finally {
        clearTimeout(deadline);
      }
    })();

    return shutdownPromise;
  };

  process.once('SIGINT', () => {
    void shutdown('SIGINT');
  });
  process.once('SIGTERM', () => {
    void shutdown('SIGTERM');
  });
}
