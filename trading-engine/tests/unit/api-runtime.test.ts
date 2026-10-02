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
  it('starts and shuts down without constructing or closing Redis', async () => {
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
    const dependencies: StartApiDependencies = {
      databaseFactory: (): typeof database => database,
      appBuilder,
    };

    const runtime = await startApi(
      {
        config: identity.config,
        logger: pino({ level: 'silent' }),
        runMigrations: false,
      },
      dependencies,
    );

    expect(appBuilder).toHaveBeenCalledOnce();
    expect(listen).toHaveBeenCalledWith({
      host: identity.config.host,
      port: identity.config.port,
    });

    await Promise.all([runtime.close(), runtime.close()]);

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
