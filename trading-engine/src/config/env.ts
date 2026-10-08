import { createPublicKey } from 'node:crypto';

export type LogLevel = 'fatal' | 'error' | 'warn' | 'info' | 'debug' | 'trace';

export interface EngineConfig {
  readonly nodeEnv: 'development' | 'test' | 'production';
  readonly serviceName: string;
  readonly serviceVersion: string;
  readonly host: string;
  readonly port: number;
  readonly logLevel: LogLevel;
  readonly databaseUrl: string;
  readonly databaseMaxConnections: number;
  readonly databaseSsl: boolean;
  readonly databaseCaCertificate: string | undefined;
  readonly redisUrl: string;
  readonly redisPrefix: string;
  readonly serviceAuthIssuer: string;
  readonly serviceAuthAudience: string;
  readonly serviceAuthPublicKey: ReturnType<typeof createPublicKey>;
  readonly serviceAuthMaxTokenAgeSeconds: number;
  readonly serviceAuthClockToleranceSeconds: number;
  readonly laravelWebhookUrl: URL;
  readonly laravelWebhookHmacSecret: string;
  readonly laravelWebhookTimeoutMs: number;
  readonly outboxBatchSize: number;
  readonly outboxPollIntervalMs: number;
  readonly workflowIdleMaxIntervalMs: number;
  readonly outboxClaimTtlMs: number;
  readonly evaluationBatchSize: number;
  readonly evaluationClaimTtlMs: number;
  readonly workflowLeaderRetryIntervalMs: number;
  readonly paperEntryEnabled: boolean;
  readonly paperOpeningBalanceNative: string;
  readonly paperEntryNotionalNative: string;
  readonly paperEntryIntentMaxAgeSeconds: number;
  readonly paperFinancialLifecycleEnabled: boolean;
  readonly paperObservationMaxAgeSeconds: number;
  readonly shutdownTimeoutMs: number;
}

export class EnvironmentConfigurationError extends Error {
  public constructor(message: string) {
    super(message);
    this.name = 'EnvironmentConfigurationError';
  }
}

const logLevels = new Set<LogLevel>(['fatal', 'error', 'warn', 'info', 'debug', 'trace']);

function required(env: NodeJS.ProcessEnv, key: string): string {
  const value = env[key]?.trim();

  if (value === undefined || value === '') {
    throw new EnvironmentConfigurationError(`${key} is required`);
  }

  return value;
}

function optional(env: NodeJS.ProcessEnv, key: string, defaultValue: string): string {
  const value = env[key]?.trim();

  return value === undefined || value === '' ? defaultValue : value;
}

function integer(
  env: NodeJS.ProcessEnv,
  key: string,
  defaultValue: number,
  minimum: number,
  maximum: number,
): number {
  const raw = env[key]?.trim();

  if (raw === undefined || raw === '') {
    return defaultValue;
  }

  if (!/^\d+$/.test(raw)) {
    throw new EnvironmentConfigurationError(`${key} must be an integer`);
  }

  const value = Number(raw);

  if (!Number.isSafeInteger(value) || value < minimum || value > maximum) {
    throw new EnvironmentConfigurationError(
      `${key} must be between ${minimum} and ${maximum}`,
    );
  }

  return value;
}

function boolean(env: NodeJS.ProcessEnv, key: string, defaultValue: boolean): boolean {
  const raw = env[key]?.trim().toLowerCase();

  if (raw === undefined || raw === '') {
    return defaultValue;
  }

  if (raw === 'true') {
    return true;
  }

  if (raw === 'false') {
    return false;
  }

  throw new EnvironmentConfigurationError(`${key} must be true or false`);
}

function positiveDecimal(
  env: NodeJS.ProcessEnv,
  key: string,
  defaultValue: string,
): string {
  const value = optional(env, key, defaultValue);

  if (!/^(?:0|[1-9]\d{0,47})(?:\.\d{0,29}[1-9])?$/.test(value)
    || value === '0') {
    throw new EnvironmentConfigurationError(key + ' must be a positive canonical decimal string');
  }

  return value;
}

function optionalBase64Text(
  env: NodeJS.ProcessEnv,
  key: string,
): string | undefined {
  const encoded = env[key]?.trim();

  if (encoded === undefined || encoded === '') {
    return undefined;
  }

  const isCanonicalBase64 = /^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$/.test(
    encoded,
  );

  if (!isCanonicalBase64) {
    throw new EnvironmentConfigurationError(`${key} must be valid Base64`);
  }

  const decoded = Buffer.from(encoded, 'base64');

  if (decoded.toString('base64') !== encoded) {
    throw new EnvironmentConfigurationError(`${key} must be valid Base64`);
  }

  return decoded.toString('utf8');
}

function nodeEnvironment(env: NodeJS.ProcessEnv): EngineConfig['nodeEnv'] {
  const value = env['NODE_ENV']?.trim() ?? 'development';

  if (value !== 'development' && value !== 'test' && value !== 'production') {
    throw new EnvironmentConfigurationError(
      'NODE_ENV must be development, test, or production',
    );
  }

  return value;
}

function publicKey(env: NodeJS.ProcessEnv): ReturnType<typeof createPublicKey> {
  const encoded = required(env, 'SERVICE_AUTH_PUBLIC_KEY_BASE64');

  try {
    return createPublicKey({
      key: Buffer.from(encoded, 'base64'),
      format: 'der',
      type: 'spki',
    });
  } catch (error) {
    throw new EnvironmentConfigurationError(
      `SERVICE_AUTH_PUBLIC_KEY_BASE64 must contain an Ed25519 SPKI DER public key: ${error instanceof Error ? error.message : 'invalid key'}`,
    );
  }
}

function url(env: NodeJS.ProcessEnv, key: string): URL {
  const value = required(env, key);

  try {
    return new URL(value);
  } catch {
    throw new EnvironmentConfigurationError(`${key} must be an absolute URL`);
  }
}

export function loadConfig(env: NodeJS.ProcessEnv = process.env): EngineConfig {
  const logLevel = (env['LOG_LEVEL']?.trim() ?? 'info') as LogLevel;

  if (!logLevels.has(logLevel)) {
    throw new EnvironmentConfigurationError('LOG_LEVEL is invalid');
  }

  const webhookSecret = required(env, 'LARAVEL_WEBHOOK_HMAC_SECRET');

  if (Buffer.byteLength(webhookSecret) < 32) {
    throw new EnvironmentConfigurationError(
      'LARAVEL_WEBHOOK_HMAC_SECRET must contain at least 32 bytes',
    );
  }

  const outboxPollIntervalMs = integer(
    env,
    'OUTBOX_POLL_INTERVAL_MS',
    1_000,
    100,
    60_000,
  );
  const workflowIdleMaxIntervalMs = integer(
    env,
    'WORKFLOW_IDLE_MAX_INTERVAL_MS',
    Math.max(20_000, outboxPollIntervalMs),
    outboxPollIntervalMs,
    60_000,
  );

  return {
    nodeEnv: nodeEnvironment(env),
    serviceName: optional(env, 'SERVICE_NAME', 'meme-scanner-trading-engine'),
    serviceVersion: optional(env, 'SERVICE_VERSION', '0.1.0'),
    host: optional(env, 'HOST', '127.0.0.1'),
    port: integer(env, 'PORT', 3100, 1, 65_535),
    logLevel,
    databaseUrl: required(env, 'DATABASE_URL'),
    databaseMaxConnections: integer(env, 'DATABASE_MAX_CONNECTIONS', 10, 1, 100),
    databaseSsl: boolean(env, 'DATABASE_SSL', false),
    databaseCaCertificate: optionalBase64Text(env, 'DATABASE_CA_CERT_BASE64'),
    redisUrl: required(env, 'REDIS_URL'),
    redisPrefix: optional(env, 'REDIS_PREFIX', 'meme-scanner:engine'),
    serviceAuthIssuer: required(env, 'SERVICE_AUTH_ISSUER'),
    serviceAuthAudience: required(env, 'SERVICE_AUTH_AUDIENCE'),
    serviceAuthPublicKey: publicKey(env),
    serviceAuthMaxTokenAgeSeconds: integer(
      env,
      'SERVICE_AUTH_MAX_TOKEN_AGE_SECONDS',
      60,
      1,
      300,
    ),
    serviceAuthClockToleranceSeconds: integer(
      env,
      'SERVICE_AUTH_CLOCK_TOLERANCE_SECONDS',
      5,
      0,
      30,
    ),
    laravelWebhookUrl: url(env, 'LARAVEL_WEBHOOK_URL'),
    laravelWebhookHmacSecret: webhookSecret,
    laravelWebhookTimeoutMs: integer(env, 'LARAVEL_WEBHOOK_TIMEOUT_MS', 5_000, 100, 30_000),
    outboxBatchSize: integer(env, 'OUTBOX_BATCH_SIZE', 25, 1, 500),
    outboxPollIntervalMs,
    workflowIdleMaxIntervalMs,
    outboxClaimTtlMs: integer(env, 'OUTBOX_CLAIM_TTL_MS', 30_000, 1_000, 600_000),
    evaluationBatchSize: integer(env, 'EVALUATION_BATCH_SIZE', 25, 1, 500),
    evaluationClaimTtlMs: integer(
      env,
      'EVALUATION_CLAIM_TTL_MS',
      30_000,
      1_000,
      600_000,
    ),
    workflowLeaderRetryIntervalMs: integer(
      env,
      'WORKFLOW_LEADER_RETRY_INTERVAL_MS',
      10_000,
      5_000,
      60_000,
    ),
    paperEntryEnabled: boolean(env, 'PAPER_ENTRY_ENABLED', false),
    paperOpeningBalanceNative: positiveDecimal(env, 'PAPER_OPENING_BALANCE_NATIVE', '5'),
    paperEntryNotionalNative: positiveDecimal(env, 'PAPER_ENTRY_NOTIONAL_NATIVE', '0.1'),
    paperEntryIntentMaxAgeSeconds: integer(
      env,
      'PAPER_ENTRY_INTENT_MAX_AGE_SECONDS',
      300,
      30,
      3_600,
    ),
    paperFinancialLifecycleEnabled: boolean(env, 'PAPER_FINANCIAL_LIFECYCLE_ENABLED', false),
    paperObservationMaxAgeSeconds: integer(
      env,
      'PAPER_OBSERVATION_MAX_AGE_SECONDS',
      120,
      30,
      3_600,
    ),
    shutdownTimeoutMs: integer(env, 'SHUTDOWN_TIMEOUT_MS', 15_000, 1_000, 60_000),
  };
}
