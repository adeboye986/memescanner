import {
  type Insertable,
  type Kysely,
  type Selectable,
  type Transaction,
  type Updateable,
} from 'kysely';

import type {
  Database,
  PaperPositionMonitoringTaskTable,
} from '../client.js';

export const SOLANA_MAINNET_ID = 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp';

export type PaperPositionMonitoringTaskRecord = Selectable<PaperPositionMonitoringTaskTable>;
type Connection = Kysely<Database> | Transaction<Database>;

export interface PaperMarketObservationSnapshot {
  readonly provider: 'dexscreener';
  readonly marketCapUsd: string;
  readonly priceUsd: string;
  readonly liquidityUsd: string | null;
  readonly fetchedAt: Date;
  readonly providerObservedAt: Date | null;
}

export interface CreatePaperPositionMonitoringTask {
  readonly positionId: string;
  readonly networkId: string;
  readonly assetAddress: string;
  readonly dueAt: Date;
}

export interface PaperMonitoringFailure {
  readonly errorCode: string;
  readonly httpStatus: number | null;
  readonly retryAfterMs: number | null;
}

export interface PaperPositionMonitoringStore {
  readonly createForPosition: (
    connection: Connection,
    input: CreatePaperPositionMonitoringTask,
  ) => Promise<PaperPositionMonitoringTaskRecord>;
  readonly retireClosedTasks: (
    database: Kysely<Database>,
    now: Date,
  ) => Promise<number>;
  readonly claimDue: (
    database: Kysely<Database>,
    leaseOwner: string,
    batchSize: number,
    leaseDurationMs: number,
    now: Date,
  ) => Promise<readonly PaperPositionMonitoringTaskRecord[]>;
  readonly recordSuccess: (
    database: Kysely<Database>,
    task: PaperPositionMonitoringTaskRecord,
    leaseOwner: string,
    observation: PaperMarketObservationSnapshot,
    attemptedAt: Date,
    intervalMs: number,
  ) => Promise<'rescheduled' | 'completed'>;
  readonly recordFailure: (
    database: Kysely<Database>,
    task: PaperPositionMonitoringTaskRecord,
    leaseOwner: string,
    failure: PaperMonitoringFailure,
    attemptedAt: Date,
    intervalMs: number,
    maximumBackoffMs: number,
  ) => Promise<'rescheduled' | 'completed'>;
  readonly releaseLeases: (
    database: Kysely<Database>,
    leaseOwner: string,
    now: Date,
  ) => Promise<number>;
}

export class PaperPositionMonitoringRepository implements PaperPositionMonitoringStore {
  public async createForPosition(
    connection: Connection,
    input: CreatePaperPositionMonitoringTask,
  ): Promise<PaperPositionMonitoringTaskRecord> {
    const values: Insertable<PaperPositionMonitoringTaskTable> = {
      position_id: input.positionId,
      network_id: input.networkId,
      asset_address: input.assetAddress,
      monitoring_state: 'pending',
      next_observation_due_at: input.dueAt,
      lease_owner: null,
      lease_expires_at: null,
      last_successful_observation_at: null,
      last_fetch_attempted_at: null,
      provider_backoff_until: null,
      last_error_code: null,
      last_http_status: null,
      last_provider: null,
      last_market_cap_usd: null,
      last_price_usd: null,
      last_liquidity_usd: null,
      last_fetched_at: null,
      last_provider_observed_at: null,
    };
    const inserted = await connection
      .insertInto('paper_position_monitoring_tasks')
      .values(values)
      .onConflict((conflict) => conflict.column('position_id').doNothing())
      .returningAll()
      .executeTakeFirst();
    const task = inserted ?? await connection
      .selectFrom('paper_position_monitoring_tasks')
      .selectAll()
      .where('position_id', '=', input.positionId)
      .executeTakeFirstOrThrow();

    if (task.network_id !== input.networkId
      || task.asset_address !== input.assetAddress) {
      throw new Error('Existing PAPER monitoring task conflicts with position identity');
    }

    return task;
  }

  public async retireClosedTasks(
    database: Kysely<Database>,
    now: Date,
  ): Promise<number> {
    const result = await database
      .updateTable('paper_position_monitoring_tasks as task')
      .set({
        monitoring_state: 'completed',
        lease_owner: null,
        lease_expires_at: null,
        updated_at: now,
      })
      .where('task.monitoring_state', '!=', 'completed')
      .where((expression) => expression.or([
        expression('task.monitoring_state', '!=', 'processing'),
        expression('task.lease_expires_at', '<=', now),
      ]))
      .where((expression) => expression.exists(
        expression.selectFrom('paper_positions as position')
          .select('position.id')
          .whereRef('position.id', '=', 'task.position_id')
          .where('position.state', '=', 'closed'),
      ))
      .executeTakeFirst();

    return Number(result.numUpdatedRows);
  }

  public async claimDue(
    database: Kysely<Database>,
    leaseOwner: string,
    batchSize: number,
    leaseDurationMs: number,
    now: Date,
  ): Promise<readonly PaperPositionMonitoringTaskRecord[]> {
    const leaseExpiresAt = new Date(now.getTime() + leaseDurationMs);

    return database.transaction().execute(async (transaction) => {
      const candidates = await transaction
        .selectFrom('paper_position_monitoring_tasks as task')
        .innerJoin('paper_positions as position', 'position.id', 'task.position_id')
        .select('task.position_id')
        .where('position.state', '=', 'open')
        .where((expression) => expression.or([
          expression.and([
            expression('task.monitoring_state', '=', 'pending'),
            expression('task.next_observation_due_at', '<=', now),
          ]),
          expression.and([
            expression('task.monitoring_state', '=', 'processing'),
            expression('task.lease_expires_at', '<=', now),
          ]),
        ]))
        .orderBy('task.next_observation_due_at', 'asc')
        .orderBy('task.created_at', 'asc')
        .limit(batchSize)
        .forUpdate()
        .skipLocked()
        .execute();
      const positionIds = candidates.map((candidate) => candidate.position_id);

      if (positionIds.length === 0) {
        return [];
      }

      return transaction
        .updateTable('paper_position_monitoring_tasks')
        .set({
          monitoring_state: 'processing',
          lease_owner: leaseOwner,
          lease_expires_at: leaseExpiresAt,
          updated_at: now,
        })
        .where('position_id', 'in', positionIds)
        .returningAll()
        .execute();
    });
  }

  public async recordSuccess(
    database: Kysely<Database>,
    task: PaperPositionMonitoringTaskRecord,
    leaseOwner: string,
    observation: PaperMarketObservationSnapshot,
    attemptedAt: Date,
    intervalMs: number,
  ): Promise<'rescheduled' | 'completed'> {
    return database.transaction().execute(async (transaction) => {
      const current = await this.lockOwnedTask(transaction, task.position_id, leaseOwner);
      const position = await transaction
        .selectFrom('paper_positions')
        .select('state')
        .where('id', '=', task.position_id)
        .forUpdate()
        .executeTakeFirstOrThrow();

      if (position.state !== 'open') {
        await this.complete(transaction, current, attemptedAt);

        return 'completed';
      }

      await transaction
        .updateTable('paper_position_monitoring_tasks')
        .set({
          monitoring_state: 'pending',
          next_observation_due_at: new Date(attemptedAt.getTime() + intervalMs),
          lease_owner: null,
          lease_expires_at: null,
          last_successful_observation_at: observation.fetchedAt,
          last_fetch_attempted_at: attemptedAt,
          consecutive_failure_count: 0,
          provider_backoff_until: null,
          last_error_code: null,
          last_http_status: null,
          last_provider: observation.provider,
          last_market_cap_usd: observation.marketCapUsd,
          last_price_usd: observation.priceUsd,
          last_liquidity_usd: observation.liquidityUsd,
          last_fetched_at: observation.fetchedAt,
          last_provider_observed_at: observation.providerObservedAt,
          updated_at: attemptedAt,
        })
        .where('position_id', '=', current.position_id)
        .where('monitoring_state', '=', 'processing')
        .where('lease_owner', '=', leaseOwner)
        .executeTakeFirstOrThrow();

      return 'rescheduled';
    });
  }

  public async recordFailure(
    database: Kysely<Database>,
    task: PaperPositionMonitoringTaskRecord,
    leaseOwner: string,
    failure: PaperMonitoringFailure,
    attemptedAt: Date,
    intervalMs: number,
    maximumBackoffMs: number,
  ): Promise<'rescheduled' | 'completed'> {
    this.assertSafeErrorCode(failure.errorCode);

    return database.transaction().execute(async (transaction) => {
      const current = await this.lockOwnedTask(transaction, task.position_id, leaseOwner);
      const position = await transaction
        .selectFrom('paper_positions')
        .select('state')
        .where('id', '=', task.position_id)
        .forUpdate()
        .executeTakeFirstOrThrow();

      if (position.state !== 'open') {
        await this.complete(transaction, current, attemptedAt);

        return 'completed';
      }

      const failures = current.consecutive_failure_count + 1;
      const exponential = intervalMs * 2 ** Math.min(failures - 1, 20);
      const requested = Math.max(exponential, failure.retryAfterMs ?? 0);
      const backoffMs = Math.min(maximumBackoffMs, requested);
      const nextAttemptAt = new Date(attemptedAt.getTime() + backoffMs);

      await transaction
        .updateTable('paper_position_monitoring_tasks')
        .set({
          monitoring_state: 'pending',
          next_observation_due_at: nextAttemptAt,
          lease_owner: null,
          lease_expires_at: null,
          last_fetch_attempted_at: attemptedAt,
          consecutive_failure_count: failures,
          provider_backoff_until: nextAttemptAt,
          last_error_code: failure.errorCode,
          last_http_status: failure.httpStatus,
          updated_at: attemptedAt,
        })
        .where('position_id', '=', current.position_id)
        .where('monitoring_state', '=', 'processing')
        .where('lease_owner', '=', leaseOwner)
        .executeTakeFirstOrThrow();

      return 'rescheduled';
    });
  }

  public async releaseLeases(
    database: Kysely<Database>,
    leaseOwner: string,
    now: Date,
  ): Promise<number> {
    const values: Updateable<PaperPositionMonitoringTaskTable> = {
      monitoring_state: 'pending',
      next_observation_due_at: now,
      lease_owner: null,
      lease_expires_at: null,
      updated_at: now,
    };
    const result = await database
      .updateTable('paper_position_monitoring_tasks')
      .set(values)
      .where('monitoring_state', '=', 'processing')
      .where('lease_owner', '=', leaseOwner)
      .executeTakeFirst();

    return Number(result.numUpdatedRows);
  }

  private async lockOwnedTask(
    transaction: Transaction<Database>,
    positionId: string,
    leaseOwner: string,
  ): Promise<PaperPositionMonitoringTaskRecord> {
    const task = await transaction
      .selectFrom('paper_position_monitoring_tasks')
      .selectAll()
      .where('position_id', '=', positionId)
      .where('monitoring_state', '=', 'processing')
      .where('lease_owner', '=', leaseOwner)
      .forUpdate()
      .executeTakeFirst();

    if (task === undefined) {
      throw new Error('PAPER monitoring task lease is no longer owned');
    }

    return task;
  }

  private async complete(
    transaction: Transaction<Database>,
    task: PaperPositionMonitoringTaskRecord,
    now: Date,
  ): Promise<void> {
    await transaction
      .updateTable('paper_position_monitoring_tasks')
      .set({
        monitoring_state: 'completed',
        lease_owner: null,
        lease_expires_at: null,
        updated_at: now,
      })
      .where('position_id', '=', task.position_id)
      .executeTakeFirstOrThrow();
  }

  private assertSafeErrorCode(errorCode: string): void {
    if (!/^[A-Z][A-Z0-9_]{0,127}$/.test(errorCode)) {
      throw new Error('PAPER monitoring failure code is invalid');
    }
  }
}
