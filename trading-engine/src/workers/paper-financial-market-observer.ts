import { createHash } from 'node:crypto';

import type { Kysely } from 'kysely';

import type { ObservePaperFinancialPositionCommandHandler } from '../application/handlers/observe-paper-financial-position-command-handler.js';
import type { PaperFinancialObservationResponse } from '../contracts/http/paper-financial-observation-command.schema.js';
import type { Database } from '../infrastructure/database/client.js';
import {
  SOLANA_MAINNET_ID,
  type PaperMarketObservationSnapshot,
  type PaperPositionMonitoringTaskRecord,
} from '../infrastructure/database/repositories/paper-position-monitoring-repository.js';
import { ApplicationError } from '../shared/errors/application-error.js';

const INTERNAL_MONITOR_SUBJECT = 'meme-scanner-trading-engine-monitor';

export interface PaperFinancialMarketObserver {
  readonly observe: (
    task: PaperPositionMonitoringTaskRecord,
    observation: PaperMarketObservationSnapshot,
  ) => Promise<PaperFinancialObservationResponse>;
}

export class EnginePaperFinancialMarketObserver implements PaperFinancialMarketObserver {
  public constructor(
    private readonly database: Kysely<Database>,
    private readonly handler: ObservePaperFinancialPositionCommandHandler,
  ) {}

  public async observe(
    task: PaperPositionMonitoringTaskRecord,
    observation: PaperMarketObservationSnapshot,
  ): Promise<PaperFinancialObservationResponse> {
    if (task.observation_authority !== 'financial') {
      throw new ApplicationError(
        'PAPER_MARKET_AUTHORITY_INVALID',
        'The monitoring task is not authorized to submit financial observations',
        409,
        false,
      );
    }

    const position = await this.database
      .selectFrom('paper_positions')
      .select([
        'id',
        'control_plane_user_id',
        'network_id',
        'asset_address',
      ])
      .where('id', '=', task.position_id)
      .executeTakeFirst();

    if (position?.network_id !== task.network_id
      || position.asset_address !== task.asset_address
      || task.network_id !== SOLANA_MAINNET_ID) {
      throw new ApplicationError(
        'PAPER_FINANCIAL_POSITION_CORRELATION_INVALID',
        'The monitoring task does not match its engine-owned PAPER position',
        409,
        false,
      );
    }

    const sequence = task.next_financial_observation_sequence;
    const idempotencyKey = `paper:financial-monitor:${task.position_id}:${sequence}:v1`;
    const observedAt = observation.providerObservedAt ?? observation.fetchedAt;

    return this.handler.execute({
      body: {
        schema_version: 1,
        position_id: task.position_id,
        source: {
          system: INTERNAL_MONITOR_SUBJECT,
          observation_id: `paper-monitor:${task.position_id}:${sequence}`,
          sequence,
        },
        subject: {
          control_plane_user_id: position.control_plane_user_id,
        },
        network: {
          id: SOLANA_MAINNET_ID,
        },
        asset: {
          address: task.asset_address,
        },
        market: {
          market_cap_usd: observation.marketCapUsd,
          price_usd: observation.priceUsd,
          ...(observation.liquidityUsd === null
            ? {}
            : { liquidity_usd: observation.liquidityUsd }),
          observed_at: observedAt.toISOString(),
          fetched_at: observation.fetchedAt.toISOString(),
          provider: observation.provider,
        },
        validation: {
          status: 'eligible',
          identity_verified: true,
          simulation_allowed: true,
        },
      },
      idempotencyKey,
      authJti: idempotencyKey,
      subject: INTERNAL_MONITOR_SUBJECT,
      correlationId: idempotencyKey,
      traceparent: traceparent(idempotencyKey),
    });
  }
}

function traceparent(seed: string): string {
  const hash = createHash('sha256').update(seed).digest('hex');

  return `00-${hash.slice(0, 32)}-${hash.slice(32, 48)}-01`;
}
