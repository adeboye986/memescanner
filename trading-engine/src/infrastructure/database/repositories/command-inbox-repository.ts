import type { Insertable, Kysely, Selectable, Transaction, Updateable } from 'kysely';

import type {
  CommandInboxTable,
  Database,
  JsonValue,
} from '../client.js';

type Connection = Kysely<Database> | Transaction<Database>;
export type CommandInboxRecord = Selectable<CommandInboxTable>;

export interface NewCommandInboxRecord {
  readonly id: string;
  readonly idempotencyKey: string;
  readonly authJti: string;
  readonly subject: string;
  readonly commandName: string;
  readonly requestHash: string;
  readonly correlationId: string;
  readonly traceparent: string;
}

export class CommandInboxRepository {
  public async insert(
    connection: Connection,
    record: NewCommandInboxRecord,
  ): Promise<CommandInboxRecord | undefined> {
    const values: Insertable<CommandInboxTable> = {
      id: record.id,
      idempotency_key: record.idempotencyKey,
      auth_jti: record.authJti,
      subject: record.subject,
      command_name: record.commandName,
      request_hash: record.requestHash,
      status: 'processing',
      response: null,
      correlation_id: record.correlationId,
      traceparent: record.traceparent,
    };

    return connection
      .insertInto('command_inbox')
      .values(values)
      .onConflict((conflict) => conflict.column('idempotency_key').doNothing())
      .returningAll()
      .executeTakeFirst();
  }

  public async findByIdempotencyKey(
    connection: Connection,
    idempotencyKey: string,
  ): Promise<CommandInboxRecord | undefined> {
    return connection
      .selectFrom('command_inbox')
      .selectAll()
      .where('idempotency_key', '=', idempotencyKey)
      .executeTakeFirst();
  }

  public async complete(
    connection: Connection,
    id: string,
    response: JsonValue,
  ): Promise<void> {
    const values: Updateable<CommandInboxTable> = {
      status: 'succeeded',
      response,
      completed_at: new Date(),
    };

    const result = await connection
      .updateTable('command_inbox')
      .set(values)
      .where('id', '=', id)
      .where('status', '=', 'processing')
      .executeTakeFirst();

    if (result.numUpdatedRows !== 1n) {
      throw new Error('Command inbox transition was not applied exactly once');
    }
  }
}
