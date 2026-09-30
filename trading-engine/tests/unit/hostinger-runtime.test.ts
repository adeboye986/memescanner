import pino from 'pino';
import { describe, expect, it, vi } from 'vitest';

import {
  startHostingerRuntime,
  type HostingerRuntimeDependencies,
} from '../../apps/hostinger/src/runtime.js';
import type { RuntimeHandle } from '../../src/infrastructure/runtime/process-lifecycle.js';
import type { Telemetry } from '../../src/infrastructure/telemetry/instrumentation.js';
import { createTestIdentity } from '../support/test-environment.js';

function runtimeHandle(name: string, events: string[]): RuntimeHandle {
  return {
    close: vi.fn((): Promise<void> => {
      events.push(`close:${name}`);

      return Promise.resolve();
    }),
  };
}

function telemetry(events: string[]): Telemetry {
  return {
    tracer: {} as Telemetry['tracer'],
    meter: {} as Telemetry['meter'],
    shutdown: vi.fn((): Promise<void> => {
      events.push('close:telemetry');

      return Promise.resolve();
    }),
  };
}

function dependencies(
  events: string[],
  overrides: Partial<HostingerRuntimeDependencies> = {},
): HostingerRuntimeDependencies {
  const config = createTestIdentity().config;

  return {
    config,
    logger: pino({ level: 'silent' }),
    telemetry: telemetry(events),
    migrate: vi.fn((): Promise<void> => {
      events.push('migrate');

      return Promise.resolve();
    }),
    apiStarter: vi.fn((options): Promise<RuntimeHandle> => {
      events.push(`start:api:${String(options.runMigrations)}`);

      return Promise.resolve(runtimeHandle('api', events));
    }),
    workerStarter: vi.fn((options): Promise<RuntimeHandle> => {
      events.push(`start:worker:${String(options.runMigrations)}`);

      return Promise.resolve(runtimeHandle('worker', events));
    }),
    schedulerStarter: vi.fn((): Promise<RuntimeHandle> => {
      events.push('start:scheduler');

      return Promise.resolve(runtimeHandle('scheduler', events));
    }),
    ...overrides,
  };
}

describe('combined Hostinger runtime', () => {
  it('runs one migration before starting all roles without child migrations', async () => {
    const events: string[] = [];

    const runtime = await startHostingerRuntime(dependencies(events));

    expect(events).toEqual([
      'migrate',
      'start:api:false',
      'start:worker:false',
      'start:scheduler',
    ]);
    await runtime.close();
  });

  it('closes all roles in safe order and makes shutdown idempotent', async () => {
    const events: string[] = [];
    const runtime = await startHostingerRuntime(dependencies(events));
    events.length = 0;

    await Promise.all([runtime.close(), runtime.close()]);

    expect(events).toEqual([
      'close:scheduler',
      'close:api',
      'close:worker',
      'close:telemetry',
    ]);
  });

  it('cleans up previously started roles when later startup fails', async () => {
    const events: string[] = [];
    const startupError = new Error('synthetic scheduler startup failure');
    const configured = dependencies(events, {
      schedulerStarter: vi.fn((): Promise<RuntimeHandle> => {
        events.push('start:scheduler');

        return Promise.reject(startupError);
      }),
    });

    await expect(startHostingerRuntime(configured)).rejects.toBe(startupError);

    expect(events).toEqual([
      'migrate',
      'start:api:false',
      'start:worker:false',
      'start:scheduler',
      'close:worker',
      'close:api',
      'close:telemetry',
    ]);
  });

  it('continues closing remaining owners after one shutdown step fails', async () => {
    const events: string[] = [];
    const configured = dependencies(events, {
      schedulerStarter: vi.fn((): Promise<RuntimeHandle> => Promise.resolve({
        close: vi.fn((): Promise<void> => {
          events.push('close:scheduler');

          return Promise.reject(new Error('synthetic close failure'));
        }),
      })),
    });
    const runtime = await startHostingerRuntime(configured);
    events.length = 0;

    await expect(runtime.close()).rejects.toThrow('combined runtime shutdown failed');

    expect(events).toEqual([
      'close:scheduler',
      'close:api',
      'close:worker',
      'close:telemetry',
    ]);
  });
});
