import { createLogger } from '../../api/src/app.js';
import { loadConfig } from '../../../src/config/env.js';
import {
  loadLocalEnvironment,
  runManagedProcess,
} from '../../../src/infrastructure/runtime/process-lifecycle.js';
import { startTelemetry } from '../../../src/infrastructure/telemetry/instrumentation.js';
import { startScheduler } from './runtime.js';

loadLocalEnvironment();

const config = loadConfig();
const logger = createLogger(config);
const telemetry = startTelemetry(config);

await runManagedProcess({
  name: 'scheduler',
  shutdownTimeoutMs: config.shutdownTimeoutMs,
  logger,
  start: () => startScheduler({ config, logger }),
  finalize: () => telemetry.shutdown(),
});
