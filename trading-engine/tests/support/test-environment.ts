import { generateKeyPairSync, type KeyObject } from 'node:crypto';

import { PostgreSqlContainer, type StartedPostgreSqlContainer } from '@testcontainers/postgresql';
import { RedisContainer, type StartedRedisContainer } from '@testcontainers/redis';
import { SignJWT } from 'jose';
import type { Kysely } from 'kysely';
import { Redis } from 'ioredis';
import { sql } from 'kysely';

import { loadConfig, type EngineConfig } from '../../src/config/env.js';
import { createDatabase, type Database } from '../../src/infrastructure/database/client.js';
import { migrateDatabase } from '../../src/infrastructure/database/migrate.js';

export interface TestEnvironment {
  readonly databaseUrl: string;
  readonly redisUrl: string;
  readonly postgresContainer?: StartedPostgreSqlContainer;
  readonly redisContainer?: StartedRedisContainer;
}

export interface TestIdentity {
  readonly config: EngineConfig;
  readonly privateKey: KeyObject;
}

export async function startTestEnvironment(): Promise<TestEnvironment> {
  const configuredDatabaseUrl = process.env['DATABASE_URL'];
  const configuredRedisUrl = process.env['REDIS_URL'];

  if (configuredDatabaseUrl !== undefined && configuredRedisUrl !== undefined) {
    return {
      databaseUrl: configuredDatabaseUrl,
      redisUrl: configuredRedisUrl,
    };
  }

  const [postgresContainer, redisContainer] = await Promise.all([
    new PostgreSqlContainer('postgres:17.6-alpine')
      .withDatabase('trading_engine_test')
      .withUsername('trading_engine')
      .withPassword('test_only_password')
      .start(),
    new RedisContainer('redis:8.2.1-alpine').start(),
  ]);

  return {
    databaseUrl: postgresContainer.getConnectionUri(),
    redisUrl: redisContainer.getConnectionUrl(),
    postgresContainer,
    redisContainer,
  };
}

export async function stopTestEnvironment(environment: TestEnvironment): Promise<void> {
  await Promise.all([
    environment.postgresContainer?.stop(),
    environment.redisContainer?.stop(),
  ]);
}

export function createTestIdentity(
  environment?: Pick<TestEnvironment, 'databaseUrl' | 'redisUrl'>,
  overrides: Readonly<Record<string, string>> = {},
): TestIdentity {
  const { privateKey, publicKey } = generateKeyPairSync('ed25519');
  const publicKeyBase64 = publicKey
    .export({
      format: 'der',
      type: 'spki',
    })
    .toString('base64');
  const env: NodeJS.ProcessEnv = {
    NODE_ENV: 'test',
    SERVICE_NAME: 'trading-engine-test',
    SERVICE_VERSION: '0.1.0-test',
    HOST: '127.0.0.1',
    PORT: '3100',
    LOG_LEVEL: 'fatal',
    DATABASE_URL:
      environment?.databaseUrl
      ?? 'postgresql://trading_engine:test@127.0.0.1:5433/trading_engine_test',
    DATABASE_MAX_CONNECTIONS: '5',
    DATABASE_SSL: 'false',
    REDIS_URL: environment?.redisUrl ?? 'redis://127.0.0.1:6380',
    REDIS_PREFIX: `meme-scanner:test:${process.pid}`,
    SERVICE_AUTH_ISSUER: 'laravel-test',
    SERVICE_AUTH_AUDIENCE: 'trading-engine-test',
    SERVICE_AUTH_PUBLIC_KEY_BASE64: publicKeyBase64,
    SERVICE_AUTH_MAX_TOKEN_AGE_SECONDS: '60',
    SERVICE_AUTH_CLOCK_TOLERANCE_SECONDS: '0',
    LARAVEL_WEBHOOK_URL: 'http://127.0.0.1:4100/internal/trading-engine/events',
    LARAVEL_WEBHOOK_HMAC_SECRET: 'test-only-secret-that-is-longer-than-32-bytes',
    LARAVEL_WEBHOOK_TIMEOUT_MS: '2000',
    OUTBOX_BATCH_SIZE: '25',
    OUTBOX_POLL_INTERVAL_MS: '1000',
    OUTBOX_CLAIM_TTL_MS: '1000',
    SHUTDOWN_TIMEOUT_MS: '5000',
    ...overrides,
  };

  return {
    config: loadConfig(env),
    privateKey,
  };
}

export async function createServiceToken(
  identity: TestIdentity,
  options: {
    readonly subject?: string;
    readonly jti?: string;
    readonly scopes: readonly string[];
    readonly issuedAt?: number;
    readonly expiresAt?: number;
  },
): Promise<string> {
  const issuedAt = options.issuedAt ?? Math.floor(Date.now() / 1_000);
  const expiresAt = options.expiresAt ?? issuedAt + 30;

  return new SignJWT({
    scope: options.scopes.join(' '),
  })
    .setProtectedHeader({
      alg: 'EdDSA',
      typ: 'JWT',
    })
    .setIssuer(identity.config.serviceAuthIssuer)
    .setAudience(identity.config.serviceAuthAudience)
    .setSubject(options.subject ?? 'laravel-service')
    .setJti(options.jti ?? `test-jti-${issuedAt}`)
    .setIssuedAt(issuedAt)
    .setExpirationTime(expiresAt)
    .sign(identity.privateKey);
}

export async function resetDatabase(database: Kysely<Database>): Promise<void> {
  await sql`
    drop table if exists event_delivery_attempts cascade;
    drop table if exists event_outbox cascade;
    drop table if exists command_inbox cascade;
    drop table if exists kysely_migration cascade;
    drop table if exists kysely_migration_lock cascade;
  `.execute(database);
  await migrateDatabase(database);
}

export async function resetRedis(redisUrl: string): Promise<void> {
  const redis = new Redis(redisUrl, {
    lazyConnect: true,
    maxRetriesPerRequest: 1,
  });

  try {
    await redis.connect();
    await redis.flushdb();
  } finally {
    await redis.quit();
  }
}

export function databaseFor(environment: TestEnvironment): Kysely<Database> {
  return createDatabase(createTestIdentity(environment).config);
}
