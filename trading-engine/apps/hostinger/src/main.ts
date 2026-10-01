import { loadConfig } from '../../../src/config/env.js';
import {
  loadLocalEnvironment,
  runManagedProcess,
} from '../../../src/infrastructure/runtime/process-lifecycle.js';
import { startTelemetry } from '../../../src/infrastructure/telemetry/instrumentation.js';
import { createLogger } from '../../api/src/app.js';
import { startHostingerRuntime } from './runtime.js';

loadLocalEnvironment();

const config = loadConfig();
const logger = createLogger(config);

async function bootstrapHostingerRuntime(): Promise<void> {
  const telemetry = startTelemetry(config);

  await runManagedProcess({
    name: 'combined Hostinger runtime',
    shutdownTimeoutMs: config.shutdownTimeoutMs,
    logger,
    start: () => startHostingerRuntime({ config, logger, telemetry }),
  });
}

void bootstrapHostingerRuntime().catch((error: unknown): void => {
  logger.fatal({ err: error }, 'combined Hostinger runtime bootstrap failed');
  process.exitCode = 1;
});
