import { Writable } from 'node:stream';

import type { Kysely } from 'kysely';
import type { Redis } from 'ioredis';
import { describe, expect, it } from 'vitest';

import { AcceptNoopCommandHandler } from '../../src/application/handlers/accept-noop-command-handler.js';
import type { Database } from '../../src/infrastructure/database/client.js';
import { CommandInboxRepository } from '../../src/infrastructure/database/repositories/command-inbox-repository.js';
import { OutboxRepository } from '../../src/infrastructure/database/repositories/outbox-repository.js';
import {
  buildApp,
  createLogger,
} from '../../apps/api/src/app.js';
import {
  createServiceToken,
  createTestIdentity,
} from '../support/test-environment.js';

function inertDependencies(): {
  readonly database: Kysely<Database>;
  readonly redis: Redis;
  readonly handler: AcceptNoopCommandHandler;
} {
  const database = {} as Kysely<Database>;

  return {
    database,
    redis: {} as Redis,
    handler: new AcceptNoopCommandHandler(
      database,
      new CommandInboxRepository(),
      new OutboxRepository(),
    ),
  };
}

describe('health and version contracts', () => {
  it('returns 401 when no service assertion is provided', async () => {
    const identity = createTestIdentity();
    const dependencies = inertDependencies();
    const app = buildApp({
      config: identity.config,
      database: dependencies.database,
      redis: dependencies.redis,
      noopHandler: dependencies.handler,
    });

    const response = await app.inject({
      method: 'GET',
      url: '/v1/health/live',
    });

    expect(response.statusCode).toBe(401);
    expect(response.json()).toMatchObject({
      error: {
        code: 'AUTH_REQUIRED',
        retryable: false,
      },
    });
    await app.close();
  });

  it('returns 401 when the service assertion is expired', async () => {
    const identity = createTestIdentity();
    const dependencies = inertDependencies();
    const app = buildApp({
      config: identity.config,
      database: dependencies.database,
      redis: dependencies.redis,
      noopHandler: dependencies.handler,
    });
    const now = Math.floor(Date.now() / 1_000);
    const token = await createServiceToken(identity, {
      scopes: ['health:read'],
      issuedAt: now - 90,
      expiresAt: now - 30,
    });

    const response = await app.inject({
      method: 'GET',
      url: '/v1/health/live',
      headers: {
        authorization: `Bearer ${token}`,
      },
    });

    expect(response.statusCode).toBe(401);
    expect(response.json()).toMatchObject({
      error: {
        code: 'AUTH_INVALID',
      },
    });
    await app.close();
  });

  it('returns 403 when the required scope is absent', async () => {
    const identity = createTestIdentity();
    const dependencies = inertDependencies();
    const app = buildApp({
      config: identity.config,
      database: dependencies.database,
      redis: dependencies.redis,
      noopHandler: dependencies.handler,
    });
    const token = await createServiceToken(identity, {
      scopes: ['commands:noop'],
    });

    const response = await app.inject({
      method: 'GET',
      url: '/v1/health/live',
      headers: {
        authorization: `Bearer ${token}`,
      },
    });

    expect(response.statusCode).toBe(403);
    expect(response.json()).toMatchObject({
      error: {
        code: 'AUTH_SCOPE_DENIED',
      },
    });
    await app.close();
  });

  it('returns authenticated liveness and version metadata without secrets', async () => {
    const identity = createTestIdentity();
    const dependencies = inertDependencies();
    const app = buildApp({
      config: identity.config,
      database: dependencies.database,
      redis: dependencies.redis,
      noopHandler: dependencies.handler,
    });
    const token = await createServiceToken(identity, {
      scopes: ['health:read'],
    });
    const headers = {
      authorization: `Bearer ${token}`,
      'x-correlation-id': 'contract-correlation-1',
      traceparent: '00-11111111111111111111111111111111-2222222222222222-01',
    };

    const liveness = await app.inject({
      method: 'GET',
      url: '/v1/health/live',
      headers,
    });
    const version = await app.inject({
      method: 'GET',
      url: '/v1/version',
      headers,
    });

    expect(liveness.statusCode).toBe(200);
    expect(liveness.headers['x-correlation-id']).toBe('contract-correlation-1');
    expect(liveness.headers['traceparent']).toBe(headers.traceparent);
    expect(liveness.json()).toMatchObject({
      status: 'ok',
      service: 'trading-engine-test',
      version: '0.1.0-test',
    });
    expect(JSON.stringify(liveness.json())).not.toContain('password');
    expect(version.statusCode).toBe(200);
    expect(version.json()).toEqual({
      service: 'trading-engine-test',
      version: '0.1.0-test',
      node: process.version,
    });
    await app.close();
  });

  it('redacts authentication and secret fields from structured logs', async () => {
    const identity = createTestIdentity(undefined, { LOG_LEVEL: 'info' });
    const chunks: string[] = [];
    const destination = new Writable({
      write(
        chunk: string | Buffer,
        _encoding: BufferEncoding,
        callback: (error?: Error | null) => void,
      ): void {
        chunks.push(typeof chunk === "string" ? chunk : chunk.toString("utf8"));
        callback();
      },
    });
    const logger = createLogger(identity.config, destination);

    logger.info(
      {
        authorization: 'Bearer should-not-appear',
        password: 'database-password',
        nested: {
          secret: 'webhook-secret',
        },
      },
      'redaction check',
    );
    await new Promise<void>((resolve, reject) => {
      logger.flush((error) => {
        if (error !== undefined) {
          reject(error);
          return;
        }

        resolve();
      });
    });
    const output = chunks.join('');

    expect(output).toContain('[REDACTED]');
    expect(output).not.toContain('should-not-appear');
    expect(output).not.toContain('database-password');
    expect(output).not.toContain('webhook-secret');
  });
});
