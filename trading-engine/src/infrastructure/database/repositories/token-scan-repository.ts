import {
  sql,
  type Insertable,
  type Kysely,
  type Selectable,
  type Transaction,
  type Updateable,
} from 'kysely';

import type { TokenScanCommand } from '../../../contracts/http/token-scan-command.schema.js';
import type {
  Database,
  JsonValue,
  TokenDiscoveryTable,
  TokenScanExecutionTable,
} from '../client.js';

type Connection = Kysely<Database> | Transaction<Database>;
export type TokenScanExecutionRecord = Selectable<TokenScanExecutionTable>;
export type TokenDiscoveryRecord = Selectable<TokenDiscoveryTable>;

export interface TokenScanCounts {
  readonly discovered: number;
  readonly examined: number;
  readonly qualified: number;
  readonly opportunitiesCreated: number;
  readonly opportunitiesExisting: number;
  readonly skipped: number;
  readonly failed: number;
}

export interface TokenDiscoveryInput {
  readonly id: string;
  readonly scanExecutionId: string;
  readonly networkId: string;
  readonly assetAddress: string;
  readonly symbol: string | null;
  readonly name: string | null;
  readonly provider: TokenDiscoveryTable['provider'];
  readonly discoveryIndex: number;
  readonly state: TokenDiscoveryTable['state'];
  readonly reasonCode: string | null;
  readonly marketSnapshot: JsonValue | null;
  readonly securitySnapshot: JsonValue | null;
  readonly qualificationSnapshot: JsonValue | null;
  readonly opportunityId: string | null;
}

export class TokenScanRepository {
  public async insertExecution(
    connection: Connection,
    input: {
      readonly id: string;
      readonly command: TokenScanCommand;
      readonly requestSha256: string;
      readonly idempotencyKey: string;
      readonly correlationId: string;
      readonly traceparent: string;
    },
  ): Promise<void> {
    const values: Insertable<TokenScanExecutionTable> = {
      id: input.id,
      source_system: input.command.source.system,
      source_activity_id: input.command.source.activity_id,
      control_plane_user_id: input.command.subject.control_plane_user_id,
      scanner: input.command.scanner,
      network_id: input.command.network.id,
      state: 'pending',
      lease_owner: null,
      lease_expires_at: null,
      request: input.command,
      request_sha256: input.requestSha256,
      idempotency_key: input.idempotencyKey,
      correlation_id: input.correlationId,
      traceparent: input.traceparent,
      provider_failures: JSON.stringify([]),
      last_error_code: null,
      started_at: null,
      completed_at: null,
    };

    await connection.insertInto('token_scan_executions').values(values).execute();
  }

  public async claimBatch(
    database: Kysely<Database>,
    claimedBy: string,
    batchSize: number,
    leaseDurationMs: number,
  ): Promise<readonly TokenScanExecutionRecord[]> {
    return database.transaction().execute(async (transaction) => {
      const candidates = await transaction
        .selectFrom('token_scan_executions')
        .select('id')
        .where((expression) => expression.or([
          expression.and([
            expression('state', '=', 'pending'),
            expression('attempt_count', '<', 5),
            expression('next_attempt_at', '<=', sql<Date>`clock_timestamp()`),
          ]),
          expression.and([
            expression('state', '=', 'processing'),
            expression('attempt_count', '<', 5),
            expression('lease_expires_at', '<=', sql<Date>`clock_timestamp()`),
          ]),
        ]))
        .orderBy('created_at', 'asc')
        .limit(batchSize)
        .forUpdate()
        .skipLocked()
        .execute();
      const ids = candidates.map((candidate) => candidate.id);

      if (ids.length === 0) {
        return [];
      }

      return transaction
        .updateTable('token_scan_executions')
        .set((expression) => ({
          state: 'processing',
          attempt_count: expression('attempt_count', '+', 1),
          lease_owner: claimedBy,
          lease_expires_at: sql<Date>`clock_timestamp() + ${leaseDurationMs} * interval '1 millisecond'`,
          started_at: sql<Date>`coalesce(started_at, clock_timestamp())`,
          updated_at: sql<Date>`clock_timestamp()`,
        }))
        .where('id', 'in', ids)
        .returningAll()
        .execute();
    });
  }

  public async recordDiscovery(
    database: Kysely<Database>,
    execution: TokenScanExecutionRecord,
    input: TokenDiscoveryInput,
  ): Promise<void> {
    await database.transaction().execute(async (transaction) => {
      await this.assertOwned(transaction, execution);
      const values: Insertable<TokenDiscoveryTable> = {
        id: input.id,
        scan_execution_id: input.scanExecutionId,
        network_id: input.networkId,
        asset_address: input.assetAddress,
        symbol: input.symbol,
        name: input.name,
        provider: input.provider,
        discovery_index: input.discoveryIndex,
        state: input.state,
        reason_code: input.reasonCode,
        market_snapshot: input.marketSnapshot,
        security_snapshot: input.securitySnapshot,
        qualification_snapshot: input.qualificationSnapshot,
        opportunity_id: input.opportunityId,
      };

      await transaction
        .insertInto('token_discoveries')
        .values(values)
        .onConflict((conflict) => conflict
          .columns(['scan_execution_id', 'network_id', 'asset_address'])
          .doUpdateSet({
            symbol: input.symbol,
            name: input.name,
            state: input.state,
            reason_code: input.reasonCode,
            market_snapshot: input.marketSnapshot,
            security_snapshot: input.securitySnapshot,
            qualification_snapshot: input.qualificationSnapshot,
            opportunity_id: input.opportunityId,
            updated_at: new Date(),
          }))
        .execute();
    });
  }

  public async complete(
    database: Kysely<Database>,
    execution: TokenScanExecutionRecord,
    counts: TokenScanCounts,
    providerFailures: readonly string[],
  ): Promise<boolean> {
    const state = providerFailures.length === 0 && counts.failed === 0
      ? 'succeeded'
      : 'succeeded_with_warnings';
    const values: Updateable<TokenScanExecutionTable> = {
      state,
      lease_owner: null,
      lease_expires_at: null,
      discovered_count: counts.discovered,
      examined_count: counts.examined,
      qualified_count: counts.qualified,
      opportunities_created_count: counts.opportunitiesCreated,
      opportunities_existing_count: counts.opportunitiesExisting,
      skipped_count: counts.skipped,
      failed_count: counts.failed,
      provider_failures: JSON.stringify(providerFailures),
      last_error_code: null,
      completed_at: new Date(),
      updated_at: new Date(),
    };
    const result = await database
      .updateTable('token_scan_executions')
      .set(values)
      .where('id', '=', execution.id)
      .where('state', '=', 'processing')
      .where('attempt_count', '=', execution.attempt_count)
      .where('lease_owner', '=', execution.lease_owner)
      .where('lease_expires_at', '>', sql<Date>`clock_timestamp()`)
      .executeTakeFirst();

    return result.numUpdatedRows === 1n;
  }

  public async fail(
    database: Kysely<Database>,
    execution: TokenScanExecutionRecord,
    errorCode: string,
  ): Promise<boolean> {
    const result = await database
      .updateTable('token_scan_executions')
      .set({
        state: 'failed',
        lease_owner: null,
        lease_expires_at: null,
        last_error_code: errorCode,
        completed_at: new Date(),
        updated_at: new Date(),
      })
      .where('id', '=', execution.id)
      .where('state', '=', 'processing')
      .where('attempt_count', '=', execution.attempt_count)
      .where('lease_owner', '=', execution.lease_owner)
      .where('lease_expires_at', '>', sql<Date>`clock_timestamp()`)
      .executeTakeFirst();

    return result.numUpdatedRows === 1n;
  }

  public async retry(
    database: Kysely<Database>,
    execution: TokenScanExecutionRecord,
    errorCode: string,
    delayMs: number,
  ): Promise<boolean> {
    const result = await database
      .updateTable('token_scan_executions')
      .set({
        state: 'pending',
        next_attempt_at: sql<Date>`clock_timestamp() + ${delayMs} * interval '1 millisecond'`,
        lease_owner: null,
        lease_expires_at: null,
        last_error_code: errorCode,
        updated_at: sql<Date>`clock_timestamp()`,
      })
      .where('id', '=', execution.id)
      .where('state', '=', 'processing')
      .where('attempt_count', '=', execution.attempt_count)
      .where('lease_owner', '=', execution.lease_owner)
      .where('lease_expires_at', '>', sql<Date>`clock_timestamp()`)
      .executeTakeFirst();

    return result.numUpdatedRows === 1n;
  }

  public async renewLease(
    database: Kysely<Database>,
    execution: TokenScanExecutionRecord,
    leaseDurationMs: number,
  ): Promise<boolean> {
    const result = await database
      .updateTable('token_scan_executions')
      .set({
        lease_expires_at: sql<Date>`clock_timestamp() + ${leaseDurationMs} * interval '1 millisecond'`,
        updated_at: sql<Date>`clock_timestamp()`,
      })
      .where('id', '=', execution.id)
      .where('state', '=', 'processing')
      .where('attempt_count', '=', execution.attempt_count)
      .where('lease_owner', '=', execution.lease_owner)
      .where('lease_expires_at', '>', sql<Date>`clock_timestamp()`)
      .executeTakeFirst();

    return result.numUpdatedRows === 1n;
  }

  public async releaseClaims(database: Kysely<Database>, claimedBy: string): Promise<void> {
    await database
      .updateTable('token_scan_executions')
      .set({
        state: 'pending',
        lease_owner: null,
        lease_expires_at: null,
        updated_at: new Date(),
      })
      .where('state', '=', 'processing')
      .where('lease_owner', '=', claimedBy)
      .execute();
  }

  private async assertOwned(
    transaction: Transaction<Database>,
    execution: TokenScanExecutionRecord,
  ): Promise<void> {
    const owned = await transaction
      .selectFrom('token_scan_executions')
      .select('id')
      .where('id', '=', execution.id)
      .where('state', '=', 'processing')
      .where('attempt_count', '=', execution.attempt_count)
      .where('lease_owner', '=', execution.lease_owner)
      .where('lease_expires_at', '>', sql<Date>`clock_timestamp()`)
      .forUpdate()
      .executeTakeFirst();

    if (owned === undefined) {
      throw new Error('Token scan execution claim is no longer owned');
    }
  }
}
