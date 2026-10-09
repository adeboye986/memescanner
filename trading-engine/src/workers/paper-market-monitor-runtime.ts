import { sql, type Kysely } from 'kysely';
import type { Logger } from 'pino';

import type { EngineConfig } from '../config/env.js';
import type { Database } from '../infrastructure/database/client.js';
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
    ?? paperMarketMonitoringCapabilitiesAvailable;
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
            eligibleControlPlaneUserIds: options.config.paperMarketMonitorCanaryUserIds,
            intervalMs: options.config.paperMarketMonitorIntervalMs,
            batchSize: options.config.paperMarketMonitorBatchSize,
            requestBudget: options.config.paperMarketMonitorRequestBudget,
            maximumBackoffMs: options.config.paperMarketMonitorMaximumBackoffMs,
            leaseDurationMs: options.config.paperMarketMonitorLeaseDurationMs,
          },
        }),
      });
      poller = created;

      if (isStopping()) {
        await created.close();
        return;
      }

      created.start();
      options.logger.info(
        { intervalMs: options.config.paperMarketMonitorIntervalMs },
        'Leader-controlled PAPER shadow monitoring started',
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
): Promise<boolean> {
  const result = await sql<{ readonly available: boolean }>`
    select
      to_regclass('public.paper_position_monitoring_tasks') is not null
      and to_regclass('public.paper_market_shadow_observations') is not null
      and exists (
        select 1
        from information_schema.columns
        where table_schema = 'public'
          and table_name = 'paper_position_monitoring_tasks'
          and column_name = 'next_attempt_sequence'
      )
      and exists (
        select 1
        from pg_constraint
        where conname = 'paper_market_shadow_observations_position_attempt_unique'
          and conrelid = to_regclass('public.paper_market_shadow_observations')
      )
      and exists (
        select 1
        from pg_trigger
        where tgname = 'paper_market_shadow_observations_append_only'
          and tgrelid = to_regclass('public.paper_market_shadow_observations')
          and not tgisinternal
      ) as available
  `.execute(database);

  return result.rows[0]?.available === true;
}

function createMonitoringCycle(
  options: PaperMarketMonitoringCycleFactoryOptions,
): PaperMarketMonitoringCycleRunner {
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
