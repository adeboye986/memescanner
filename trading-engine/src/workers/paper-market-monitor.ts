import type { Kysely } from 'kysely';
import type { Logger } from 'pino';

import type { Database } from '../infrastructure/database/client.js';
import type {
  PaperMarketRequestDetails,
  PaperMonitoringFailure,
  PaperPositionMonitoringStore,
  PaperPositionMonitoringTaskRecord,
} from '../infrastructure/database/repositories/paper-position-monitoring-repository.js';
import {
  DEXSCREENER_MAX_TOKEN_ADDRESSES,
  PaperMarketProviderError,
  type PaperMarketDataProvider,
  type PaperMarketFetchResult,
} from '../infrastructure/market-data/dexscreener-paper-market-provider.js';
import { newEngineId } from '../shared/ids/id.js';

export interface PaperMarketMonitoringPolicy {
  readonly enabled: boolean;
  readonly eligibleControlPlaneUserIds: readonly string[];
  readonly intervalMs: number;
  readonly batchSize: number;
  readonly requestBudget: number;
  readonly maximumBackoffMs: number;
  readonly leaseDurationMs: number;
}

export interface PaperMarketMonitoringSummary {
  readonly enabled: boolean;
  readonly retired: number;
  readonly claimed: number;
  readonly providerRequests: number;
  readonly succeeded: number;
  readonly failed: number;
  readonly completed: number;
}

export class PaperMarketMonitoringCycle {
  private readonly leaseOwner = newEngineId();

  public constructor(
    private readonly database: Kysely<Database>,
    private readonly repository: PaperPositionMonitoringStore,
    private readonly provider: PaperMarketDataProvider,
    private readonly policy: PaperMarketMonitoringPolicy,
    private readonly logger: Logger,
    private readonly now: () => Date = () => new Date(),
  ) {}

  public async runCycle(): Promise<PaperMarketMonitoringSummary> {
    if (!this.policy.enabled) {
      return this.summary(false);
    }

    const startedAt = this.now();
    const retired = await this.repository.retireClosedTasks(
      this.database,
      this.policy.eligibleControlPlaneUserIds,
      startedAt,
    );
    const claimLimit = Math.min(
      this.policy.batchSize,
      this.policy.requestBudget * DEXSCREENER_MAX_TOKEN_ADDRESSES,
    );
    const tasks = await this.repository.claimDue(
      this.database,
      this.leaseOwner,
      this.policy.eligibleControlPlaneUserIds,
      claimLimit,
      this.policy.leaseDurationMs,
      startedAt,
    );
    let providerRequests = 0;
    let succeeded = 0;
    let failed = 0;
    let completed = 0;

    for (let index = 0; index < tasks.length; index += DEXSCREENER_MAX_TOKEN_ADDRESSES) {
      const batch = tasks.slice(index, index + DEXSCREENER_MAX_TOKEN_ADDRESSES);
      const requestStartedAt = this.now();
      const requestId = newEngineId(requestStartedAt.getTime());
      providerRequests += 1;
      let results: readonly PaperMarketFetchResult[];

      try {
        results = await this.provider.fetchSolana(
          batch.map((task) => task.asset_address),
        );
      } catch (error) {
        const request = this.requestDetails(requestId, requestStartedAt);
        const providerFailure = providerError(error);
        this.logger.warn(
          {
            errorCode: providerFailure.errorCode,
            httpStatus: providerFailure.httpStatus,
            tasks: batch.length,
          },
          'PAPER market monitoring provider batch failed',
        );

        for (const task of batch) {
          const outcome = await this.failTask(task, providerFailure, request);
          failed += outcome === 'rescheduled' ? 1 : 0;
          completed += outcome === 'completed' ? 1 : 0;
        }

        continue;
      }

      const request = this.requestDetails(requestId, requestStartedAt);
      const byAddress = new Map(results.map((result) => [
        result.available ? result.observation.assetAddress : result.assetAddress,
        result,
      ]));

      for (const task of batch) {
        const result = byAddress.get(task.asset_address);

        if (result?.available === true) {
          const outcome = await this.repository.recordSuccess(
            this.database,
            task,
            this.leaseOwner,
            {
              provider: result.observation.provider,
              pairAddress: result.observation.pairAddress,
              dex: result.observation.dex,
              marketCapUsd: result.observation.marketCapUsd,
              priceUsd: result.observation.priceUsd,
              liquidityUsd: result.observation.liquidityUsd,
              fetchedAt: result.observation.fetchedAt,
              providerObservedAt: result.observation.providerObservedAt,
            },
            request,
            this.policy.intervalMs,
          );
          succeeded += outcome === 'rescheduled' ? 1 : 0;
          completed += outcome === 'completed' ? 1 : 0;
        } else {
          const outcome = await this.failTask(
            task,
            result === undefined
              ? failure('PROVIDER_RESPONSE_MALFORMED', 'failed')
              : failure(result.errorCode, 'unavailable'),
            request,
          );
          failed += outcome === 'rescheduled' ? 1 : 0;
          completed += outcome === 'completed' ? 1 : 0;
        }
      }
    }

    return {
      enabled: true,
      retired,
      claimed: tasks.length,
      providerRequests,
      succeeded,
      failed,
      completed,
    };
  }

  public async shutdown(): Promise<void> {
    await this.repository.releaseLeases(
      this.database,
      this.leaseOwner,
      this.now(),
    );
  }

  private async failTask(
    task: PaperPositionMonitoringTaskRecord,
    providerFailure: PaperMonitoringFailure,
    request: PaperMarketRequestDetails,
  ): Promise<'rescheduled' | 'completed'> {
    return this.repository.recordFailure(
      this.database,
      task,
      this.leaseOwner,
      providerFailure,
      request,
      this.policy.intervalMs,
      this.policy.maximumBackoffMs,
    );
  }

  private requestDetails(
    requestId: PaperMarketRequestDetails['requestId'],
    startedAt: Date,
  ): PaperMarketRequestDetails {
    const measuredAt = this.now();
    const receivedAt = measuredAt.getTime() < startedAt.getTime() ? startedAt : measuredAt;

    return {
      requestId,
      startedAt,
      receivedAt,
      latencyMs: receivedAt.getTime() - startedAt.getTime(),
    };
  }

  private summary(enabled: boolean): PaperMarketMonitoringSummary {
    return {
      enabled,
      retired: 0,
      claimed: 0,
      providerRequests: 0,
      succeeded: 0,
      failed: 0,
      completed: 0,
    };
  }
}

function providerError(error: unknown): PaperMonitoringFailure {
  if (error instanceof PaperMarketProviderError) {
    return {
      outcome: 'failed',
      errorCode: error.errorCode,
      httpStatus: error.statusCode,
      retryAfterMs: error.retryAfterMs,
    };
  }

  return failure('PROVIDER_UNEXPECTED_FAILURE', 'failed');
}

function failure(
  errorCode: string,
  outcome: PaperMonitoringFailure['outcome'],
): PaperMonitoringFailure {
  return {
    outcome,
    errorCode,
    httpStatus: null,
    retryAfterMs: null,
  };
}
