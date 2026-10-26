import type { Kysely } from 'kysely';
import type { Logger } from 'pino';

import { ObservePaperFinancialPositionCommandHandler } from '../application/handlers/observe-paper-financial-position-command-handler.js';
import type { EngineConfig } from '../config/env.js';
import type { Database } from '../infrastructure/database/client.js';
import { inspectDatabaseSchemaCompatibility } from '../infrastructure/database/schema-compatibility.js';
import { CommandInboxRepository } from '../infrastructure/database/repositories/command-inbox-repository.js';
import { OutboxRepository } from '../infrastructure/database/repositories/outbox-repository.js';
import { PaperFinancialLifecycleRepository } from '../infrastructure/database/repositories/paper-financial-lifecycle-repository.js';
import { PaperPositionLifecycleRepository } from '../infrastructure/database/repositories/paper-position-lifecycle-repository.js';
import { PaperPositionMonitoringRepository } from '../infrastructure/database/repositories/paper-position-monitoring-repository.js';
import {
  DEXSCREENER_MAX_TOKEN_ADDRESSES,
  DexScreenerPaperMarketProvider,
  NodeHttpsPaperMarketTransport,
} from '../infrastructure/market-data/dexscreener-paper-market-provider.js';
import {
  idempotentClose,
  type RuntimeHandle,
} from '../infrastructure/runtime/process-lifecycle.js';
import { EnginePaperFinancialMarketObserver } from './paper-financial-market-observer.js';
import {
  PaperMarketMonitoringCycle,
  type PaperMarketMonitoringPolicy,
} from './paper-market-monitor.js';
import {
  PaperMarketMonitorPoller,
  type PaperMarketMonitoringCycleRunner,
  type PaperMarketMonitorPollerHandle,
  type PaperMarketMonitorPollerOptions,
} from './paper-market-monitor-poller.js';

export interface PaperMarketMonitorRuntimeHandle extends RuntimeHandle {
  readonly completed: Promise<void>;
}

export interface PaperMarketMonitorRuntimeOptions {
  readonly config: EngineConfig;
  readonly database: Kysely<Database>;
  readonly logger: Logger;
  readonly beforeCycle: () => Promise<boolean>;
}

interface PaperMarketMonitoringCycleFactoryOptions {
  readonly database: Kysely<Database>;
  readonly logger: Logger;
  readonly policy: PaperMarketMonitoringPolicy;
  readonly config: EngineConfig;
  readonly beforePersist: () => Promise<boolean>;
}

export interface PaperMarketMonitorRuntimeDependencies {
  readonly capabilityChecker?: (database: Kysely<Database>) => Promise<boolean>;
  readonly cycleFactory?: (
    options: PaperMarketMonitoringCycleFactoryOptions,
  ) => PaperMarketMonitoringCycleRunner;
  readonly pollerFactory?: (
    options: PaperMarketMonitorPollerOptions,
  ) => PaperMarketMonitorPollerHandle;
}

export function startPaperMarketMonitorRuntime(
  options: PaperMarketMonitorRuntimeOptions,
  dependencies: PaperMarketMonitorRuntimeDependencies = {},
): PaperMarketMonitorRuntimeHandle {
  const capabilityChecker = dependencies.capabilityChecker
    ?? ((database): Promise<boolean> => paperMarketMonitoringCapabilitiesAvailable(
      database,
      options.config.paperMarketMonitoringAuthorityEnabled,
    ));
  const cycleFactory = dependencies.cycleFactory ?? createMonitoringCycle;
  const pollerFactory = dependencies.pollerFactory
    ?? ((pollerOptions): PaperMarketMonitorPollerHandle =>
      new PaperMarketMonitorPoller(pollerOptions));
  let stopping = false;
  let poller: PaperMarketMonitorPollerHandle | undefined;
  const isStopping = (): boolean => stopping;

  const initialize = async (): Promise<void> => {
    if (!options.config.paperMarketMonitoringEnabled) {
      return;
    }

    if (!operationalBudgetIsSafe(options.config)) {
      options.logger.error(
        { errorCode: 'PAPER_MARKET_MONITOR_OPERATIONAL_BUDGET_UNSAFE' },
        'PAPER market monitoring was not started',
      );

      return;
    }

    try {
      if (!await capabilityChecker(options.database)) {
        options.logger.error(
          { errorCode: 'PAPER_MARKET_MONITOR_SCHEMA_UNAVAILABLE' },
          'PAPER market monitoring was not started',
        );

        return;
      }
    } catch {
      options.logger.error(
        { errorCode: 'PAPER_MARKET_MONITOR_SCHEMA_CHECK_FAILED' },
        'PAPER market monitoring was not started',
      );

      return;
    }

    if (isStopping()) {
      return;
    }

    try {
      const created = pollerFactory({
        intervalMs: options.config.paperMarketMonitorIntervalMs,
        logger: options.logger,
        beforeCycle: options.beforeCycle,
        cycle: cycleFactory({
          database: options.database,
          logger: options.logger,
          config: options.config,
          policy: {
            enabled: true,
            authoritativeEnabled: options.config.paperMarketMonitoringAuthorityEnabled,
            eligibleControlPlaneUserIds: options.config.paperMarketMonitorCanaryUserIds,
            intervalMs: options.config.paperMarketMonitorIntervalMs,
            batchSize: options.config.paperMarketMonitorBatchSize,
            requestBudget: options.config.paperMarketMonitorRequestBudget,
            maximumBackoffMs: options.config.paperMarketMonitorMaximumBackoffMs,
            leaseDurationMs: options.config.paperMarketMonitorLeaseDurationMs,
          },
          beforePersist: options.beforeCycle,
        }),
      });
      poller = created;

      if (isStopping()) {
        await created.close();
        return;
      }

      created.start();
      options.logger.info(
        {
          intervalMs: options.config.paperMarketMonitorIntervalMs,
          authority: options.config.paperMarketMonitoringAuthorityEnabled
            ? 'financial'
            : 'shadow',
        },
        'Leader-controlled PAPER market monitoring started',
      );
    } catch {
      options.logger.error(
        { errorCode: 'PAPER_MARKET_MONITOR_START_FAILED' },
        'PAPER market monitoring was not started',
      );
    }
  };

  const initialized = initialize();
  const completed = (async (): Promise<void> => {
    await initialized;
    await poller?.completed;
  })().catch((): void => {
    options.logger.error(
      { errorCode: 'PAPER_MARKET_MONITOR_STOPPED_UNEXPECTEDLY' },
      'PAPER market monitoring stopped unexpectedly',
    );
  });
  const close = idempotentClose(async (): Promise<void> => {
    stopping = true;
    await initialized;
    await poller?.close();
    await completed;
  });

  return { close, completed };
}

export async function paperMarketMonitoringCapabilitiesAvailable(
  database: Kysely<Database>,
  authoritativeEnabled = false,
): Promise<boolean> {
  const compatibility = await inspectDatabaseSchemaCompatibility(database, {
    paperEntryEnabled: false,
    paperMarketMonitoringEnabled: true,
    paperMarketMonitoringAuthorityEnabled: authoritativeEnabled,
    paperMarketMonitorCanaryUserIds: ['capability-check'],
  });

  return compatibility.compatible;
}

function createMonitoringCycle(
  options: PaperMarketMonitoringCycleFactoryOptions,
): PaperMarketMonitoringCycleRunner {
  const financialObserver = options.policy.authoritativeEnabled
    ? new EnginePaperFinancialMarketObserver(
        options.database,
        new ObservePaperFinancialPositionCommandHandler(
          options.database,
          new CommandInboxRepository(),
          new PaperPositionLifecycleRepository(),
          new PaperFinancialLifecycleRepository(),
          new OutboxRepository(),
          {
            enabled: true,
            observationMaxAgeSeconds: options.config.paperObservationMaxAgeSeconds,
          },
        ),
      )
    : undefined;

  return new PaperMarketMonitoringCycle(
    options.database,
    new PaperPositionMonitoringRepository(),
    new DexScreenerPaperMarketProvider(
      new NodeHttpsPaperMarketTransport(),
      {
        connectionTimeoutMs: options.config.paperMarketProviderConnectionTimeoutMs,
        totalTimeoutMs: options.config.paperMarketProviderTimeoutMs,
      },
    ),
    options.policy,
    options.logger,
    undefined,
    options.beforePersist,
    financialObserver,
  );
}

function operationalBudgetIsSafe(config: EngineConfig): boolean {
  const providerRequests = Math.min(
    config.paperMarketMonitorRequestBudget,
    Math.ceil(config.paperMarketMonitorBatchSize / DEXSCREENER_MAX_TOKEN_ADDRESSES),
  );
  const maximumProviderDurationMs = providerRequests
    * config.paperMarketProviderTimeoutMs;

  return maximumProviderDurationMs < config.shutdownTimeoutMs
    && maximumProviderDurationMs < config.paperMarketMonitorLeaseDurationMs;
}
