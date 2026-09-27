import type {
  Insertable,
  Kysely,
  Selectable,
  Transaction,
  Updateable,
} from 'kysely';

import type { EventEnvelope } from '../../../contracts/events/event-envelope.schema.js';
import type {
  Database,
  EventDeliveryAttemptTable,
  EventOutboxTable,
} from '../client.js';

type Connection = Kysely<Database> | Transaction<Database>;
export type OutboxRecord = Selectable<EventOutboxTable>;

export class OutboxRepository {
  public async enqueue(
    connection: Connection,
    envelope: EventEnvelope,
  ): Promise<void> {
    const values: Insertable<EventOutboxTable> = {
      id: envelope.event_id,
      event_type: envelope.event_type,
      aggregate_type: envelope.aggregate_type,
      aggregate_id: envelope.aggregate_id,
      aggregate_version: envelope.aggregate_version,
      envelope,
      payload_sha256: envelope.payload_sha256,
      correlation_id: envelope.correlation_id,
      traceparent: envelope.traceparent,
      status: 'pending',
      available_at: new Date(),
      claimed_by: null,
      claimed_at: null,
      published_at: null,
    };

    await connection.insertInto('event_outbox').values(values).execute();
  }

  public async claimBatch(
    database: Kysely<Database>,
    claimedBy: string,
    batchSize: number,
    claimTtlMs: number,
  ): Promise<readonly OutboxRecord[]> {
    const staleBefore = new Date(Date.now() - claimTtlMs);

    return database.transaction().execute(async (transaction) => {
      const candidates = await transaction
        .selectFrom('event_outbox')
        .select('id')
        .where((expression) =>
          expression.or([
            expression.and([
              expression('status', '=', 'pending'),
              expression('available_at', '<=', new Date()),
            ]),
            expression.and([
              expression('status', '=', 'processing'),
              expression('claimed_at', '<', staleBefore),
            ]),
          ]),
        )
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
        .updateTable('event_outbox')
        .set({
          status: 'processing',
          claimed_by: claimedBy,
          claimed_at: new Date(),
        })
        .where('id', 'in', ids)
        .returningAll()
        .execute();
    });
  }

  public async markPublished(
    database: Kysely<Database>,
    event: OutboxRecord,
    responseStatus: number,
  ): Promise<void> {
    await database.transaction().execute(async (transaction) => {
      await this.insertAttempt(transaction, event, 'published', responseStatus, null, null);
      const values: Updateable<EventOutboxTable> = {
        status: 'published',
        attempt_count: event.attempt_count + 1,
        published_at: new Date(),
        claimed_by: null,
        claimed_at: null,
      };

      const result = await transaction
        .updateTable('event_outbox')
        .set(values)
        .where('id', '=', event.id)
        .where('status', '=', 'processing')
        .where('claimed_by', '=', event.claimed_by)
        .executeTakeFirst();

      if (result.numUpdatedRows !== 1n) {
        throw new Error('Published outbox claim is no longer owned');
      }
    });
  }

  public async markFailed(
    database: Kysely<Database>,
    event: OutboxRecord,
    errorCode: string,
    responseStatus: number | null,
  ): Promise<void> {
    const attemptNumber = event.attempt_count + 1;
    const delayMs = Math.min(60_000, 500 * 2 ** Math.min(attemptNumber - 1, 7));
    const nextAttemptAt = new Date(Date.now() + delayMs);

    await database.transaction().execute(async (transaction) => {
      await this.insertAttempt(
        transaction,
        event,
        'failed',
        responseStatus,
        errorCode,
        nextAttemptAt,
      );
      const values: Updateable<EventOutboxTable> = {
        status: 'pending',
        attempt_count: attemptNumber,
        available_at: nextAttemptAt,
        claimed_by: null,
        claimed_at: null,
      };

      const result = await transaction
        .updateTable('event_outbox')
        .set(values)
        .where('id', '=', event.id)
        .where('status', '=', 'processing')
        .where('claimed_by', '=', event.claimed_by)
        .executeTakeFirst();

      if (result.numUpdatedRows !== 1n) {
        throw new Error('Failed outbox claim is no longer owned');
      }
    });
  }

  public async releaseClaims(
    database: Kysely<Database>,
    claimedBy: string,
  ): Promise<void> {
    await database
      .updateTable('event_outbox')
      .set({
        status: 'pending',
        available_at: new Date(),
        claimed_by: null,
        claimed_at: null,
      })
      .where('status', '=', 'processing')
      .where('claimed_by', '=', claimedBy)
      .execute();
  }

  private async insertAttempt(
    connection: Connection,
    event: OutboxRecord,
    outcome: EventDeliveryAttemptTable['outcome'],
    responseStatus: number | null,
    errorCode: string | null,
    nextAttemptAt: Date | null,
  ): Promise<void> {
    const values: Insertable<EventDeliveryAttemptTable> = {
      event_id: event.id,
      attempt_number: event.attempt_count + 1,
      outcome,
      response_status: responseStatus,
      error_code: errorCode,
      next_attempt_at: nextAttemptAt,
    };

    await connection.insertInto('event_delivery_attempts').values(values).execute();
  }
}
