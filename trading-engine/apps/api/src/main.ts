import { existsSync } from 'node:fs';
import { loadEnvFile } from 'node:process';

import { AcceptNoopCommandHandler } from '../../../src/application/handlers/accept-noop-command-handler.js';
import { RecordOpportunityCommandHandler } from '../../../src/application/handlers/record-opportunity-command-handler.js';
import { loadConfig } from '../../../src/config/env.js';
import { createDatabase } from '../../../src/infrastructure/database/client.js';
import { migrateDatabase } from '../../../src/infrastructure/database/migrate.js';
import { CommandInboxRepository } from '../../../src/infrastructure/database/repositories/command-inbox-repository.js';
import { OpportunityRepository } from '../../../src/infrastructure/database/repositories/opportunity-repository.js';
import { OutboxRepository } from '../../../src/infrastructure/database/repositories/outbox-repository.js';
import {
  closeRedis,
  createRedisConnection,
} from '../../../src/infrastructure/queue/connection.js';
import { startTelemetry } from '../../../src/infrastructure/telemetry/instrumentation.js';
import { buildApp, createLogger } from './app.js';

if (existsSync('.env')) {
  loadEnvFile('.env');
}

const config = loadConfig();
const logger = createLogger(config);
const telemetry = startTelemetry(config);
const database = createDatabase(config);
const redis = createRedisConnection(config);
const commandInbox = new CommandInboxRepository();
const outbox = new OutboxRepository();
const opportunities = new OpportunityRepository();
const noopHandler = new AcceptNoopCommandHandler(
  database,
  commandInbox,
  outbox,
);
const opportunityHandler = new RecordOpportunityCommandHandler(
  database,
  commandInbox,
  opportunities,
  outbox,
);
const app = buildApp({
  config,
  database,
  redis,
  noopHandler,
  opportunityHandler,
  logger,
});
let shuttingDown = false;

async function shutdown(signal: string): Promise<void> {
  if (shuttingDown) {
    return;
  }

  shuttingDown = true;
  logger.info({ signal }, 'api shutdown started');
  const deadline = setTimeout(() => {
    logger.fatal({ signal }, 'api shutdown deadline exceeded');
    process.exit(1);
  }, config.shutdownTimeoutMs);
  deadline.unref();

  try {
    await app.close();
    await closeRedis(redis);
    await database.destroy();
    await telemetry.shutdown();
    clearTimeout(deadline);
    logger.info({ signal }, 'api shutdown completed');
  } catch (error) {
    logger.error({ err: error, signal }, 'api shutdown failed');
    process.exitCode = 1;
  }
}

process.once('SIGINT', () => {
  void shutdown('SIGINT');
});
process.once('SIGTERM', () => {
  void shutdown('SIGTERM');
});

try {
  await migrateDatabase(database);
  await app.listen({
    host: config.host,
    port: config.port,
  });
} catch (error) {
  logger.fatal({ err: error }, 'api startup failed');
  await shutdown('startup-error');
  process.exitCode = 1;
}
