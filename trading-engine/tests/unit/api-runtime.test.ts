import { promises as fs } from 'node:fs';

import pino from 'pino';
import { describe, expect, it, vi } from 'vitest';

import type { AppDependencies } from '../../apps/api/src/app.js';
import {
  startApi,
  type StartApiDependencies,
} from '../../apps/api/src/runtime.js';
import { createDatabase } from '../../src/infrastructure/database/client.js';
import type { EngineFastifyInstance } from '../../src/interfaces/http/health-routes.js';
import { createTestIdentity } from '../support/test-environment.js';

describe('API runtime', () => {
  it('does not run migrations by default and starts without Redis', async () => {
    const identity = createTestIdentity(undefined, {
      REDIS_URL: 'redis://127.0.0.1:1/15',
    });
    const database = createDatabase(identity.config);
    const destroyDatabase = vi
      .spyOn(database, 'destroy')
      .mockResolvedValue(undefined);
    const listen = vi.fn((): Promise<string> => Promise.resolve('http://127.0.0.1:3100'));
    const closeApp = vi.fn((): Promise<void> => Promise.resolve());
    const app = {
      listen,
      close: closeApp,
    } as unknown as EngineFastifyInstance;
    const appBuilder = vi.fn(
      (dependencies: AppDependencies): EngineFastifyInstance => {
        expect(dependencies).not.toHaveProperty('redis');

        return app;
      },
    );
    const schemaCompatibilityChecker = vi.fn(() => Promise.resolve({
      compatible: true,
      detectedVersion: 6,
      requiredVersion: 6,
      missingCapabilities: [],
    }));
    const migrationRunner = vi.fn(() => Promise.resolve({ results: [] }));
    const dependencies: StartApiDependencies = {
      databaseFactory: (): typeof database => database,
      appBuilder,
      migrationRunner,
      schemaCompatibilityChecker,
    };

    const runtime = await startApi(
      {
        config: identity.config,
        logger: pino({ level: 'silent' }),
      },
      dependencies,
    );

    expect(migrationRunner).not.toHaveBeenCalled();
    expect(appBuilder).toHaveBeenCalledOnce();
    expect(schemaCompatibilityChecker).toHaveBeenCalledWith(
      database,
      identity.config,
    );
    expect(listen).toHaveBeenCalledWith({
      host: identity.config.host,
      port: identity.config.port,
    });

    await Promise.all([runtime.close(), runtime.close()]);

    expect(closeApp).toHaveBeenCalledOnce();
    expect(destroyDatabase).toHaveBeenCalledOnce();
  });

  it('fails startup before listen when enabled capabilities require a missing migration', async () => {
    const identity = createTestIdentity(undefined, { PAPER_ENTRY_ENABLED: 'true' });
    const database = createDatabase(identity.config);
    const destroyDatabase = vi
      .spyOn(database, 'destroy')
      .mockResolvedValue(undefined);
    const listen = vi.fn((): Promise<string> => Promise.resolve('http://127.0.0.1:3100'));
    const closeApp = vi.fn((): Promise<void> => Promise.resolve());
    const app = {
      listen,
      close: closeApp,
    } as unknown as EngineFastifyInstance;
    const compatibilityError = new Error(
      'Database schema version 006 is incompatible with required version 007',
    );
    const migrationRunner = vi.fn(() => Promise.resolve({ results: [] }));

    await expect(startApi(
      {
        config: identity.config,
        logger: pino({ level: 'silent' }),
      },
      {
        databaseFactory: (): typeof database => database,
        appBuilder: (): EngineFastifyInstance => app,
        migrationRunner,
        schemaCompatibilityChecker: (): Promise<never> =>
          Promise.reject(compatibilityError),
      },
    )).rejects.toBe(compatibilityError);

    expect(migrationRunner).not.toHaveBeenCalled();
    expect(listen).not.toHaveBeenCalled();
    expect(closeApp).toHaveBeenCalledOnce();
    expect(destroyDatabase).toHaveBeenCalledOnce();
  });

  it('keeps the active API and Hostinger import graph free of Redis runtime access', async () => {
    const sources = await Promise.all([
      fs.readFile('apps/api/src/runtime.ts', 'utf8'),
      fs.readFile('apps/api/src/app.ts', 'utf8'),
      fs.readFile('apps/hostinger/src/runtime.ts', 'utf8'),
      fs.readFile('src/interfaces/http/health-routes.ts', 'utf8'),
    ]);
    const productionPath = sources.join('\n');

    expect(productionPath).not.toMatch(/from ['"]ioredis['"]/);
    expect(productionPath).not.toContain('createRedisConnection');
    expect(productionPath).not.toContain('pingRedis');
    expect(productionPath).not.toContain('closeRedis');
  });
});
