import { afterEach, describe, expect, it } from 'vitest';

import {
  assertDedicatedTestDatabaseUrl,
  assertDedicatedTestRedisUrl,
  startTestEnvironment,
} from '../support/test-environment.js';

const originalDatabaseUrl = process.env['DATABASE_URL'];
const originalRedisUrl = process.env['REDIS_URL'];

afterEach(() => {
  restoreEnvironmentValue('DATABASE_URL', originalDatabaseUrl);
  restoreEnvironmentValue('REDIS_URL', originalRedisUrl);
});

describe('test environment destructive-operation safety', () => {
  it('accepts exactly the dedicated PostgreSQL test database', () => {
    expect(() => {
      assertDedicatedTestDatabaseUrl(
        'postgresql://127.0.0.1:5432/trading_engine_test',
      );
    }).not.toThrow();
  });

  it.each([
    'postgresql://127.0.0.1:5432/trading_engine',
    'postgresql://127.0.0.1:5432/postgres',
    'postgresql://127.0.0.1:5432/',
    'postgresql://127.0.0.1:5432/?database=trading_engine_test',
    'postgresql://127.0.0.1:5432/trading_engine_test?database=trading_engine',
    'postgresql://127.0.0.1:5432/trading_engine_test?dbname=postgres',
    'not-a-postgresql-url',
  ])('rejects unsafe PostgreSQL target %s', (databaseUrl) => {
    expect(() => {
      assertDedicatedTestDatabaseUrl(databaseUrl);
    }).toThrow(
      'Refusing destructive PostgreSQL test reset',
    );
  });

  it('accepts exactly Redis database 15', () => {
    expect(() => {
      assertDedicatedTestRedisUrl('redis://127.0.0.1:6379/15');
      assertDedicatedTestRedisUrl('rediss://redis.example.test:6380/15');
    }).not.toThrow();
  });

  it.each([
    'redis://127.0.0.1:6379',
    'redis://127.0.0.1:6379/0',
    'redis://127.0.0.1:6379/14',
    'redis://127.0.0.1:6379/15/',
    'redis://127.0.0.1:6379/?db=15',
    'redis://127.0.0.1:6379/15?db=0',
    'redis://127.0.0.1:6379/15?db=15',
    'not-a-redis-url',
  ])('rejects unsafe Redis target %s', (redisUrl) => {
    expect(() => {
      assertDedicatedTestRedisUrl(redisUrl);
    }).toThrow(
      'Refusing destructive Redis test reset',
    );
  });

  it('uses safe externally supplied URLs unchanged', async () => {
    const databaseUrl = 'postgresql://127.0.0.1:5432/trading_engine_test';
    const redisUrl = 'redis://127.0.0.1:6379/15';
    process.env['DATABASE_URL'] = databaseUrl;
    process.env['REDIS_URL'] = redisUrl;

    await expect(startTestEnvironment()).resolves.toEqual({
      databaseUrl,
      redisUrl,
    });
  });

  it('refuses a partial external configuration instead of silently using containers', async () => {
    process.env['DATABASE_URL'] = 'postgresql://127.0.0.1:5432/trading_engine_test';
    delete process.env['REDIS_URL'];

    await expect(startTestEnvironment()).rejects.toThrow(
      'External integration tests require both DATABASE_URL and REDIS_URL',
    );
  });
});

function restoreEnvironmentValue(
  name: 'DATABASE_URL' | 'REDIS_URL',
  value: string | undefined,
): void {
  if (name === 'DATABASE_URL') {
    restoreDatabaseUrl(value);
    return;
  }

  restoreRedisUrl(value);
}

function restoreDatabaseUrl(value: string | undefined): void {
  if (value === undefined) {
    delete process.env['DATABASE_URL'];
    return;
  }

  process.env['DATABASE_URL'] = value;
}

function restoreRedisUrl(value: string | undefined): void {
  if (value === undefined) {
    delete process.env['REDIS_URL'];
    return;
  }

  process.env['REDIS_URL'] = value;
}
