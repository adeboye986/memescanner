import { generateKeyPairSync } from 'node:crypto';

import { describe, expect, it } from 'vitest';

import {
  EnvironmentConfigurationError,
  loadConfig,
  loadDatabaseConfig,
} from '../../src/config/env.js';
import { createDatabasePoolConfig } from '../../src/infrastructure/database/client.js';

const databaseCaCertificate = [
  '-----BEGIN CERTIFICATE-----',
  'test-only-certificate',
  '-----END CERTIFICATE-----',
  '',
].join('\n');

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
  it('loads database-only migration configuration without runtime dependencies', () => {
    const encodedCertificate = Buffer.from(databaseCaCertificate).toString(
      'base64',
    );
    const config = loadDatabaseConfig({
      DATABASE_URL: 'postgresql://engine:test@localhost:5433/engine',
      DATABASE_MAX_CONNECTIONS: '2',
      DATABASE_SSL: 'true',
      DATABASE_CA_CERT_BASE64: encodedCertificate,
    });

    expect(config).toEqual({
      databaseUrl: 'postgresql://engine:test@localhost:5433/engine',
      databaseMaxConnections: 2,
      databaseSsl: true,
      databaseCaCertificate,
    });
    expect(createDatabasePoolConfig(config).ssl).toEqual({
      rejectUnauthorized: true,
      ca: databaseCaCertificate,
    });
  });

  it('rejects missing database configuration without requiring runtime configuration', () => {
    expect(() => loadDatabaseConfig({})).toThrow(
      new EnvironmentConfigurationError('DATABASE_URL is required'),
    );
  });

  it('defaults database-only migration TLS off and pool size to ten', () => {
    const config = loadDatabaseConfig({
      DATABASE_URL: 'postgresql://engine:test@localhost:5433/engine',
    });

    expect(config.databaseMaxConnections).toBe(10);
    expect(config.databaseSsl).toBe(false);
    expect(config.databaseCaCertificate).toBeUndefined();
    expect(createDatabasePoolConfig(config).ssl).toBeUndefined();
  });

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
    expect(config.evaluationBatchSize).toBe(25);
    expect(config.evaluationClaimTtlMs).toBe(30_000);
    expect(config.workflowIdleMaxIntervalMs).toBe(20_000);
    expect(config.workflowLeaderRetryIntervalMs).toBe(10_000);
  });

  it('defaults the workflow idle maximum to the active interval when it exceeds twenty seconds', () => {
    const config = loadConfig({
      ...validEnvironment(),
      OUTBOX_POLL_INTERVAL_MS: '30000',
    });

    expect(config.outboxPollIntervalMs).toBe(30_000);
    expect(config.workflowIdleMaxIntervalMs).toBe(30_000);
  });

  it('rejects a workflow idle maximum below the active interval', () => {
    expect(() =>
      loadConfig({
        ...validEnvironment(),
        OUTBOX_POLL_INTERVAL_MS: '5000',
        WORKFLOW_IDLE_MAX_INTERVAL_MS: '4999',
      }),
    ).toThrow(/WORKFLOW_IDLE_MAX_INTERVAL_MS must be between 5000 and 60000/);
  });

  it('accepts the maximum workflow idle interval and rejects values above it', () => {
    const config = loadConfig({
      ...validEnvironment(),
      WORKFLOW_IDLE_MAX_INTERVAL_MS: '60000',
    });

    expect(config.workflowIdleMaxIntervalMs).toBe(60_000);
    expect(() =>
      loadConfig({
        ...validEnvironment(),
        WORKFLOW_IDLE_MAX_INTERVAL_MS: '60001',
      }),
    ).toThrow(/WORKFLOW_IDLE_MAX_INTERVAL_MS must be between 1000 and 60000/);
  });

  it('rejects an aggressive workflow leadership retry interval', () => {
    expect(() =>
      loadConfig({
        ...validEnvironment(),
        WORKFLOW_LEADER_RETRY_INTERVAL_MS: '4999',
      }),
    ).toThrow(/WORKFLOW_LEADER_RETRY_INTERVAL_MS must be between 5000 and 60000/);
  });


  it('defaults engine PAPER entry off with bounded financial policy', () => {
    const config = loadConfig(validEnvironment());

    expect(config.paperEntryEnabled).toBe(false);
    expect(config.paperOpeningBalanceNative).toBe('5');
    expect(config.paperEntryNotionalNative).toBe('0.1');
    expect(config.paperEntryIntentMaxAgeSeconds).toBe(300);
    expect(config.paperFinancialLifecycleEnabled).toBe(false);
    expect(config.paperObservationMaxAgeSeconds).toBe(120);
  });

  it('accepts the PAPER financial lifecycle gate and validates observation age bounds', () => {
    const config = loadConfig({
      ...validEnvironment(),
      PAPER_FINANCIAL_LIFECYCLE_ENABLED: 'true',
      PAPER_OBSERVATION_MAX_AGE_SECONDS: '300',
    });

    expect(config.paperFinancialLifecycleEnabled).toBe(true);
    expect(config.paperObservationMaxAgeSeconds).toBe(300);
    expect(() => loadConfig({
      ...validEnvironment(),
      PAPER_OBSERVATION_MAX_AGE_SECONDS: '29',
    })).toThrow(/PAPER_OBSERVATION_MAX_AGE_SECONDS must be between 30 and 3600/);
  });

  it.each(['0', '01', '1.0', '1e2', '-1', 'NaN', '1'.repeat(49), '0.' + '1'.repeat(31)])(
    'rejects non-positive or non-canonical PAPER financial value %s',
    (value) => {
      expect(() => loadConfig({
        ...validEnvironment(),
        PAPER_OPENING_BALANCE_NATIVE: value,
      })).toThrow(/PAPER_OPENING_BALANCE_NATIVE must be a positive canonical decimal string/);
    },
  );

  it('validates the PAPER entry intent lifetime bounds', () => {
    expect(() => loadConfig({
      ...validEnvironment(),
      PAPER_ENTRY_INTENT_MAX_AGE_SECONDS: '29',
    })).toThrow(/PAPER_ENTRY_INTENT_MAX_AGE_SECONDS must be between 30 and 3600/);
  });

  it('rejects a missing database URL', () => {
    const environment = validEnvironment();
    delete environment['DATABASE_URL'];

    expect(() => loadConfig(environment)).toThrow(
      new EnvironmentConfigurationError('DATABASE_URL is required'),
    );
  });

  it('disables PostgreSQL TLS when database SSL is disabled', () => {
    const config = loadConfig({
      ...validEnvironment(),
      DATABASE_SSL: 'false',
    });

    expect(config.databaseCaCertificate).toBeUndefined();
    expect(createDatabasePoolConfig(config).ssl).toBeUndefined();
  });

  it('retains certificate verification without a custom CA', () => {
    const config = loadConfig({
      ...validEnvironment(),
      DATABASE_SSL: 'true',
    });

    expect(config.databaseCaCertificate).toBeUndefined();
    expect(createDatabasePoolConfig(config).ssl).toEqual({
      rejectUnauthorized: true,
    });
  });

  it('decodes a custom CA for verified PostgreSQL TLS', () => {
    const encodedCertificate = Buffer.from(databaseCaCertificate).toString(
      'base64',
    );
    const config = loadConfig({
      ...validEnvironment(),
      DATABASE_SSL: 'true',
      DATABASE_CA_CERT_BASE64: encodedCertificate,
    });

    expect(config.databaseCaCertificate).toBe(databaseCaCertificate);
    expect(createDatabasePoolConfig(config).ssl).toEqual({
      rejectUnauthorized: true,
      ca: databaseCaCertificate,
    });
  });

  it('rejects a malformed database CA Base64 value', () => {
    expect(() =>
      loadConfig({
        ...validEnvironment(),
        DATABASE_CA_CERT_BASE64: 'not-valid-base64%%%',
      }),
    ).toThrow(
      new EnvironmentConfigurationError(
        'DATABASE_CA_CERT_BASE64 must be valid Base64',
      ),
    );
  });

  it('does not expose malformed database CA contents in configuration errors', () => {
    const malformedCertificate = 'private-certificate-contents%%%';

    try {
      loadConfig({
        ...validEnvironment(),
        DATABASE_CA_CERT_BASE64: malformedCertificate,
      });
      expect.fail('Expected malformed database CA configuration to fail');
    } catch (error) {
      expect(error).toBeInstanceOf(EnvironmentConfigurationError);

      if (!(error instanceof Error)) {
        expect.fail('Expected configuration failure to be an Error');
      }

      expect(error.message).toBe(
        'DATABASE_CA_CERT_BASE64 must be valid Base64',
      );
      expect(error.message).not.toContain(malformedCertificate);
    }
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
