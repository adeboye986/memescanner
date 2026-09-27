import { generateKeyPairSync } from 'node:crypto';

import { describe, expect, it } from 'vitest';

import {
  EnvironmentConfigurationError,
  loadConfig,
} from '../../src/config/env.js';

function validEnvironment(): NodeJS.ProcessEnv {
  const { publicKey } = generateKeyPairSync('ed25519');

  return {
    NODE_ENV: 'test',
    DATABASE_URL: 'postgresql://engine:test@localhost:5433/engine',
    REDIS_URL: 'rediss://redis.example.test:6380',
    SERVICE_AUTH_ISSUER: 'laravel',
    SERVICE_AUTH_AUDIENCE: 'engine',
    SERVICE_AUTH_PUBLIC_KEY_BASE64: publicKey
      .export({ format: 'der', type: 'spki' })
      .toString('base64'),
    LARAVEL_WEBHOOK_URL: 'https://laravel.example.test/internal/events',
    LARAVEL_WEBHOOK_HMAC_SECRET: '01234567890123456789012345678901',
  };
}

describe('environment configuration', () => {
  it('loads external database and redis URLs without changing domain configuration', () => {
    const config = loadConfig({
      ...validEnvironment(),
      DATABASE_SSL: 'true',
      PORT: '3200',
    });

    expect(config.databaseUrl).toBe(
      'postgresql://engine:test@localhost:5433/engine',
    );
    expect(config.redisUrl).toBe('rediss://redis.example.test:6380');
    expect(config.databaseSsl).toBe(true);
    expect(config.port).toBe(3200);
  });

  it('rejects a missing database URL', () => {
    const environment = validEnvironment();
    delete environment['DATABASE_URL'];

    expect(() => loadConfig(environment)).toThrow(
      new EnvironmentConfigurationError('DATABASE_URL is required'),
    );
  });

  it('rejects a short webhook secret', () => {
    expect(() =>
      loadConfig({
        ...validEnvironment(),
        LARAVEL_WEBHOOK_HMAC_SECRET: 'short',
      }),
    ).toThrow(/at least 32 bytes/);
  });

  it('rejects an invalid service authentication public key', () => {
    expect(() =>
      loadConfig({
        ...validEnvironment(),
        SERVICE_AUTH_PUBLIC_KEY_BASE64: 'not-a-key',
      }),
    ).toThrow(/Ed25519 SPKI DER public key/);
  });
});
