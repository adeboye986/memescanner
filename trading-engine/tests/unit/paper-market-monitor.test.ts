import type { Kysely } from 'kysely';
import pino from 'pino';
import { describe, expect, it, vi } from 'vitest';

import type { Database } from '../../src/infrastructure/database/client.js';
import type {
  PaperPositionMonitoringStore,
  PaperPositionMonitoringTaskRecord,
} from '../../src/infrastructure/database/repositories/paper-position-monitoring-repository.js';
import {
  PaperMarketProviderError,
  type PaperMarketDataProvider,
} from '../../src/infrastructure/market-data/dexscreener-paper-market-provider.js';
import {
  PaperMarketMonitoringCycle,
  type PaperMarketMonitoringPolicy,
} from '../../src/workers/paper-market-monitor.js';

const now = new Date('2026-10-09T12:00:00.000Z');
const positionId = '01K72G00000000000000000000';
const assetAddress = 'So11111111111111111111111111111111111111112';

function task(): PaperPositionMonitoringTaskRecord {
  return {
    position_id: positionId,
    network_id: 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp',
    asset_address: assetAddress,
    monitoring_state: 'processing',
    next_observation_due_at: now,
    lease_owner: '01K72G00000000000000000001',
    lease_expires_at: new Date(now.getTime() + 30_000),
    last_successful_observation_at: null,
    last_fetch_attempted_at: null,
    consecutive_failure_count: 0,
    provider_backoff_until: null,
    last_error_code: null,
    last_http_status: null,
    last_provider: null,
    last_market_cap_usd: null,
    last_price_usd: null,
    last_liquidity_usd: null,
    last_fetched_at: null,
    last_provider_observed_at: null,
    created_at: now,
    updated_at: now,
  };
}

function policy(enabled = true): PaperMarketMonitoringPolicy {
  return {
    enabled,
    intervalMs: 5_000,
    batchSize: 30,
    requestBudget: 1,
    maximumBackoffMs: 60_000,
    leaseDurationMs: 30_000,
  };
}

function dependencies(): {
  readonly database: Kysely<Database>;
  readonly repository: PaperPositionMonitoringStore;
  readonly provider: PaperMarketDataProvider;
} {
  return {
    database: {} as Kysely<Database>,
    repository: {
      createForPosition: vi.fn(),
      retireClosedTasks: vi.fn().mockResolvedValue(0),
      claimDue: vi.fn().mockResolvedValue([task()]),
      recordSuccess: vi.fn().mockResolvedValue('rescheduled'),
      recordFailure: vi.fn().mockResolvedValue('rescheduled'),
      releaseLeases: vi.fn().mockResolvedValue(1),
    },
    provider: {
      fetchSolana: vi.fn().mockResolvedValue([{
        available: true,
        observation: {
          networkId: 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp',
          assetAddress,
          provider: 'dexscreener',
          pairAddress: 'pair',
          dex: 'dex',
          marketCapUsd: '1000',
          priceUsd: '0.1',
          liquidityUsd: '500',
          fetchedAt: now,
          providerObservedAt: null,
        },
      }]),
    },
  };
}

describe('PAPER market monitoring cycle', () => {
  it('does no database or provider work while disabled', async () => {
    const { database, repository, provider } = dependencies();
    const cycle = new PaperMarketMonitoringCycle(
      database,
      repository,
      provider,
      policy(false),
      pino({ level: 'silent' }),
      () => now,
    );

    await expect(cycle.runCycle()).resolves.toEqual({
      enabled: false,
      retired: 0,
      claimed: 0,
      providerRequests: 0,
      succeeded: 0,
      failed: 0,
      completed: 0,
    });
    expect(repository.claimDue).not.toHaveBeenCalled();
    expect(provider.fetchSolana).not.toHaveBeenCalled();
  });

  it('fetches claimed identities and persists only monitoring diagnostics', async () => {
    const { database, repository, provider } = dependencies();
    const cycle = new PaperMarketMonitoringCycle(
      database,
      repository,
      provider,
      policy(),
      pino({ level: 'silent' }),
      () => now,
    );

    await expect(cycle.runCycle()).resolves.toMatchObject({
      claimed: 1,
      providerRequests: 1,
      succeeded: 1,
      failed: 0,
    });
    expect(provider.fetchSolana).toHaveBeenCalledWith([assetAddress]);
    expect(repository.recordSuccess).toHaveBeenCalledOnce();
    expect(repository.recordFailure).not.toHaveBeenCalled();
  });

  it('converts an unknown provider outcome to safe retry state and releases leases', async () => {
    const { database, repository, provider } = dependencies();
    vi.mocked(provider.fetchSolana).mockRejectedValue(
      new PaperMarketProviderError('PROVIDER_TIMEOUT', true),
    );
    const cycle = new PaperMarketMonitoringCycle(
      database,
      repository,
      provider,
      policy(),
      pino({ level: 'silent' }),
      () => now,
    );

    await expect(cycle.runCycle()).resolves.toMatchObject({ failed: 1 });
    expect(repository.recordFailure).toHaveBeenCalledWith(
      database,
      expect.objectContaining({ position_id: positionId }),
      expect.any(String),
      {
        errorCode: 'PROVIDER_TIMEOUT',
        httpStatus: null,
        retryAfterMs: null,
      },
      now,
      5_000,
      60_000,
    );
    await cycle.shutdown();
    expect(repository.releaseLeases).toHaveBeenCalledOnce();
  });
});
