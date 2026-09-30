import { loadConfig } from '../../../src/config/env.js';
import {
  loadLocalEnvironment,
  runManagedProcess,
} from '../../../src/infrastructure/runtime/process-lifecycle.js';
import { startTelemetry } from '../../../src/infrastructure/telemetry/instrumentation.js';
import { createLogger } from './app.js';
import { startApi } from './runtime.js';

loadLocalEnvironment();

const config = loadConfig();
const logger = createLogger(config);
const telemetry = startTelemetry(config);

await runManagedProcess({
  name: 'api',
  shutdownTimeoutMs: config.shutdownTimeoutMs,
  logger,
  start: () => startApi({ config, logger }),
  finalize: () => telemetry.shutdown(),
});
