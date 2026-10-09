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

const DEDICATED_TEST_DATABASE = 'trading_engine_test';
const DEDICATED_TEST_REDIS_INDEX = 15;

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
  const configuredDatabaseUrl = nonEmptyEnvironmentValue(process.env['DATABASE_URL']);
  const configuredRedisUrl = nonEmptyEnvironmentValue(process.env['REDIS_URL']);

  if ((configuredDatabaseUrl === undefined) !== (configuredRedisUrl === undefined)) {
    throw new Error(
      'External integration tests require both DATABASE_URL and REDIS_URL; refusing container fallback',
    );
  }

  if (configuredDatabaseUrl !== undefined && configuredRedisUrl !== undefined) {
    assertDedicatedTestDatabaseUrl(configuredDatabaseUrl);
    assertDedicatedTestRedisUrl(configuredRedisUrl);

    return {
      databaseUrl: configuredDatabaseUrl,
      redisUrl: configuredRedisUrl,
    };
  }

  const [postgresContainer, redisContainer] = await Promise.all([
    new PostgreSqlContainer('postgres:17.6-alpine')
      .withDatabase(DEDICATED_TEST_DATABASE)
      .withUsername('trading_engine')
      .withPassword('test_only_password')
      .start(),
    new RedisContainer('redis:8.2.1-alpine').start(),
  ]);
  const databaseUrl = postgresContainer.getConnectionUri();
  const redisUrl = withRedisDatabase(redisContainer.getConnectionUrl());

  assertDedicatedTestDatabaseUrl(databaseUrl);
  assertDedicatedTestRedisUrl(redisUrl);

  return {
    databaseUrl,
    redisUrl,
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
    REDIS_URL: environment?.redisUrl ?? 'redis://127.0.0.1:6380/15',
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

export function assertDedicatedTestDatabaseUrl(databaseUrl: string): void {
  const parsed = parseUrl(databaseUrl);
  const databaseName = parsed === undefined
    || !['postgres:', 'postgresql:'].includes(parsed.protocol)
    || parsed.searchParams.has('database')
    || parsed.searchParams.has('dbname')
    ? undefined
    : decodedPathSegment(parsed);

  if (databaseName !== DEDICATED_TEST_DATABASE) {
    throw new Error(
      `Refusing destructive PostgreSQL test reset: DATABASE_URL must target ${DEDICATED_TEST_DATABASE}`,
    );
  }
}

export function assertDedicatedTestRedisUrl(redisUrl: string): void {
  const parsed = parseUrl(redisUrl);
  const databaseIndex = parsed === undefined
    || !['redis:', 'rediss:'].includes(parsed.protocol)
    || parsed.searchParams.has('db')
    ? undefined
    : decodedPathSegment(parsed);

  if (databaseIndex !== String(DEDICATED_TEST_REDIS_INDEX)) {
    throw new Error(
      `Refusing destructive Redis test reset: REDIS_URL must explicitly select database ${DEDICATED_TEST_REDIS_INDEX}`,
    );
  }
}

export async function assertDedicatedTestDatabase(
  database: Kysely<Database>,
): Promise<void> {
  const result = await sql<{ readonly database_name: string }>`
    select current_database() as database_name
  `.execute(database);

  if (result.rows[0]?.database_name !== DEDICATED_TEST_DATABASE) {
    throw new Error(
      `Refusing destructive PostgreSQL test reset: connected database must be ${DEDICATED_TEST_DATABASE}`,
    );
  }
}

export async function resetDatabase(database: Kysely<Database>): Promise<void> {
  await resetDatabaseSchema(database);
  await migrateDatabase(database);
}

export async function resetDatabaseSchema(database: Kysely<Database>): Promise<void> {
  await assertDedicatedTestDatabase(database);
  await sql`
    drop table if exists paper_exit_settlements cascade;
    drop table if exists paper_exit_fills cascade;
    drop table if exists paper_exit_orders cascade;
    drop table if exists paper_position_monitoring_tasks cascade;
    drop table if exists paper_positions cascade;
    drop table if exists paper_fills cascade;
    drop table if exists paper_orders cascade;
    drop table if exists paper_entry_intents cascade;
    drop table if exists paper_ledger_entries cascade;
    drop table if exists paper_ledger_transactions cascade;
    drop table if exists paper_wallets cascade;
    drop function if exists assert_paper_ledger_transaction_balanced();
    drop function if exists reject_paper_financial_evidence_mutation();
    drop table if exists paper_position_lifecycle_decisions cascade;
    drop table if exists paper_position_lifecycles cascade;
    drop function if exists reject_paper_lifecycle_decision_mutation();
    drop table if exists opportunity_evaluations cascade;
    drop table if exists opportunity_evaluation_tasks cascade;
    drop table if exists evaluation_policies cascade;
    drop function if exists reject_opportunity_evaluation_mutation();
    drop table if exists opportunities cascade;
    drop table if exists event_delivery_attempts cascade;
    drop table if exists event_outbox cascade;
    drop table if exists command_inbox cascade;
    drop table if exists kysely_migration cascade;
    drop table if exists kysely_migration_lock cascade;
  `.execute(database);
}

export async function resetRedis(redisUrl: string): Promise<void> {
  assertDedicatedTestRedisUrl(redisUrl);
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

function nonEmptyEnvironmentValue(value: string | undefined): string | undefined {
  return value === undefined || value.trim() === '' ? undefined : value;
}

function parseUrl(value: string): URL | undefined {
  try {
    return new URL(value);
  } catch {
    return undefined;
  }
}

function decodedPathSegment(url: URL): string | undefined {
  if (!/^\/[^/]+$/.test(url.pathname)) {
    return undefined;
  }

  try {
    return decodeURIComponent(url.pathname.slice(1));
  } catch {
    return undefined;
  }
}

function withRedisDatabase(redisUrl: string): string {
  const parsed = new URL(redisUrl);
  parsed.pathname = `/${DEDICATED_TEST_REDIS_INDEX}`;

  return parsed.toString();
}
