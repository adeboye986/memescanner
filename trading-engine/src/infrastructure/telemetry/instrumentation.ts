import { metrics, trace, type Meter, type Tracer } from '@opentelemetry/api';
import { getNodeAutoInstrumentations } from '@opentelemetry/auto-instrumentations-node';
import { resourceFromAttributes } from '@opentelemetry/resources';
import { NodeSDK } from '@opentelemetry/sdk-node';
import {
  ATTR_SERVICE_NAME,
  ATTR_SERVICE_VERSION,
} from '@opentelemetry/semantic-conventions';

import type { EngineConfig } from '../../config/env.js';

export interface Telemetry {
  readonly tracer: Tracer;
  readonly meter: Meter;
  shutdown(): Promise<void>;
}

function defaultTelemetryExporter(
  key: 'OTEL_TRACES_EXPORTER' | 'OTEL_METRICS_EXPORTER' | 'OTEL_LOGS_EXPORTER',
): void {
  const value = process.env[key]?.trim();

  if (value === undefined || value === '') {
    process.env[key] = 'none';
  }
}

export function startTelemetry(config: EngineConfig): Telemetry {
  defaultTelemetryExporter('OTEL_TRACES_EXPORTER');
  defaultTelemetryExporter('OTEL_METRICS_EXPORTER');
  defaultTelemetryExporter('OTEL_LOGS_EXPORTER');
  const sdk = new NodeSDK({
    resource: resourceFromAttributes({
      [ATTR_SERVICE_NAME]: config.serviceName,
      [ATTR_SERVICE_VERSION]: config.serviceVersion,
    }),
    instrumentations: [getNodeAutoInstrumentations()],
  });

  sdk.start();

  return {
    tracer: trace.getTracer(config.serviceName, config.serviceVersion),
    meter: metrics.getMeter(config.serviceName, config.serviceVersion),
    async shutdown(): Promise<void> {
      await sdk.shutdown();
    },
  };
}
