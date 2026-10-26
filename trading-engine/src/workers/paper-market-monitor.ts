import type { Kysely } from 'kysely';
import type { Logger } from 'pino';

import type { Database } from '../infrastructure/database/client.js';
import { ApplicationError } from '../shared/errors/application-error.js';
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
import type { PaperFinancialMarketObserver } from './paper-financial-market-observer.js';

export interface PaperMarketMonitoringPolicy {
  readonly enabled: boolean;
  readonly authoritativeEnabled?: boolean;
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
  private stopping = false;
  private activeRequest: AbortController | undefined;

  public constructor(
    private readonly database: Kysely<Database>,
    private readonly repository: PaperPositionMonitoringStore,
    private readonly provider: PaperMarketDataProvider,
    private readonly policy: PaperMarketMonitoringPolicy,
    private readonly logger: Logger,
    private readonly now: () => Date = () => new Date(),
    private readonly beforePersist: () => Promise<boolean> = () => Promise.resolve(true),
    private readonly financialObserver?: PaperFinancialMarketObserver,
  ) {}

  public async runCycle(): Promise<PaperMarketMonitoringSummary> {
    if (!this.policy.enabled || this.isStopping()) {
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
      this.policy.authoritativeEnabled === true,
    );
    let providerRequests = 0;
    let succeeded = 0;
    let failed = 0;
    let completed = 0;

    for (let index = 0; index < tasks.length; index += DEXSCREENER_MAX_TOKEN_ADDRESSES) {
      if (this.isStopping()) {
        break;
      }

      const batch = tasks.slice(index, index + DEXSCREENER_MAX_TOKEN_ADDRESSES);
      const requestStartedAt = this.now();
      const requestId = newEngineId(requestStartedAt.getTime());
      providerRequests += 1;
      let results: readonly PaperMarketFetchResult[];
      const requestController = new AbortController();
      this.activeRequest = requestController;

      try {
        results = await this.provider.fetchSolana(
          batch.map((task) => task.asset_address),
          requestController.signal,
        );
      } catch (error) {
        if (this.isStopping()) {
          break;
        }

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
          if (!await this.persistenceIsAllowed()) {
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

          const outcome = await this.failTask(task, providerFailure, request);
          failed += outcome === 'rescheduled' ? 1 : 0;
          completed += outcome === 'completed' ? 1 : 0;
        }

        continue;
      } finally {
        if (this.activeRequest === requestController) {
          this.activeRequest = undefined;
        }
      }

      const request = this.requestDetails(requestId, requestStartedAt);
      const byAddress = new Map(results.map((result) => [
        result.available ? result.observation.assetAddress : result.assetAddress,
        result,
      ]));

      for (const task of batch) {
        if (!await this.persistenceIsAllowed()) {
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

        const result = byAddress.get(task.asset_address);

        if (result?.available === true) {
          const observation = {
            provider: result.observation.provider,
            pairAddress: result.observation.pairAddress,
            dex: result.observation.dex,
            marketCapUsd: result.observation.marketCapUsd,
            priceUsd: result.observation.priceUsd,
            liquidityUsd: result.observation.liquidityUsd,
            fetchedAt: result.observation.fetchedAt,
            providerObservedAt: result.observation.providerObservedAt,
          } as const;
          let financialObservationSequence: number | undefined;

          if (task.observation_authority === 'financial') {
            if (!this.policy.authoritativeEnabled || this.financialObserver === undefined) {
              throw new Error('A financial PAPER monitoring task was claimed without authority');
            }

            try {
              await this.financialObserver.observe(task, observation);
              financialObservationSequence = task.next_financial_observation_sequence;
            } catch (error) {
              const outcome = await this.failTask(
                task,
                financialObservationFailure(error),
                request,
              );
              failed += outcome === 'rescheduled' ? 1 : 0;
              completed += outcome === 'completed' ? 1 : 0;

              continue;
            }

            if (!await this.persistenceIsAllowed()) {
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
          }

          const outcome = await this.repository.recordSuccess(
            this.database,
            task,
            this.leaseOwner,
            observation,
            request,
            this.policy.intervalMs,
            financialObservationSequence,
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

  public requestStop(): void {
    this.stopping = true;
    this.activeRequest?.abort();
  }

  public async shutdown(): Promise<void> {
    this.requestStop();
    await this.repository.releaseLeases(
      this.database,
      this.leaseOwner,
      this.now(),
    );
  }

  private isStopping(): boolean {
    return this.stopping;
  }

  private async persistenceIsAllowed(): Promise<boolean> {
    if (this.isStopping()) {
      return false;
    }

    try {
      const allowed = await this.beforePersist();

      if (!allowed) {
        this.logger.warn(
          { errorCode: 'PAPER_MARKET_MONITOR_LEADERSHIP_LOST' },
          'PAPER market monitoring leadership was lost before persistence',
        );
      }

      return allowed;
    } catch {
      this.logger.error(
        { errorCode: 'PAPER_MARKET_MONITOR_LEADERSHIP_CHECK_FAILED' },
        'PAPER market monitoring leadership check failed before persistence',
      );

      return false;
    }
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

function financialObservationFailure(error: unknown): PaperMonitoringFailure {
  if (error instanceof ApplicationError) {
    return failure(error.code, 'failed');
  }

  return failure('PAPER_FINANCIAL_OBSERVATION_FAILED', 'failed');
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
