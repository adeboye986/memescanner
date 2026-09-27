import { createHash } from 'node:crypto';

import type { Kysely } from 'kysely';

import type { AcceptNoopCommand } from '../commands/accept-noop-command.js';
import type { EventEnvelope } from '../../contracts/events/event-envelope.schema.js';
import type { NoopCommandResponse } from '../../contracts/http/noop-command.schema.js';
import type { Database, JsonValue } from '../../infrastructure/database/client.js';
import type { CommandInboxRepository } from '../../infrastructure/database/repositories/command-inbox-repository.js';
import type { OutboxRepository } from '../../infrastructure/database/repositories/outbox-repository.js';
import { ApplicationError } from '../../shared/errors/application-error.js';
import { newEngineId, type EngineId } from '../../shared/ids/id.js';

export type AcceptNoopCommandResult = NoopCommandResponse;

export class AcceptNoopCommandHandler {
  public constructor(
    private readonly database: Kysely<Database>,
    private readonly commandInbox: CommandInboxRepository,
    private readonly outbox: OutboxRepository,
    private readonly createId: () => EngineId = newEngineId,
  ) {}

  public async execute(command: AcceptNoopCommand): Promise<AcceptNoopCommandResult> {
    const requestHash = hashJson({
      command: 'foundation.accept_noop',
      message: command.message ?? null,
    });

    try {
      return await this.database.transaction().execute(async (transaction) => {
        const operationId = this.createId();
        const inserted = await this.commandInbox.insert(transaction, {
          id: operationId,
          idempotencyKey: command.idempotencyKey,
          authJti: command.authJti,
          subject: command.subject,
          commandName: 'foundation.accept_noop',
          requestHash,
          correlationId: command.correlationId,
          traceparent: command.traceparent,
        });

        if (inserted === undefined) {
          const existing = await this.commandInbox.findByIdempotencyKey(
            transaction,
            command.idempotencyKey,
          );

          if (
            existing?.request_hash !== requestHash ||
            existing.subject !== command.subject ||
            existing.command_name !== 'foundation.accept_noop'
          ) {
            throw new ApplicationError(
              'IDEMPOTENCY_CONFLICT',
              'The idempotency key was already used for another request',
              409,
              false,
            );
          }

          if (existing.status !== 'succeeded' || !isNoopResponse(existing.response)) {
            throw new ApplicationError(
              'COMMAND_IN_PROGRESS',
              'The command is still being processed',
              409,
              true,
            );
          }

          return {
            ...(existing.response as unknown as AcceptNoopCommandResult),
            duplicate: true,
          };
        }

        const eventId = this.createId();
        const payload = {
          operation_id: operationId,
          accepted_by: command.subject,
          message: command.message ?? null,
        };
        const envelope: EventEnvelope = {
          event_id: eventId,
          event_type: 'foundation.noop_accepted.v1',
          schema_version: 1,
          occurred_at: new Date().toISOString(),
          producer: 'trading-engine',
          aggregate_type: 'foundation_command',
          aggregate_id: operationId,
          aggregate_version: 1,
          correlation_id: command.correlationId,
          causation_id: operationId,
          idempotency_key: command.idempotencyKey,
          traceparent: command.traceparent,
          payload,
          payload_sha256: hashJson(payload),
        };
        const response: AcceptNoopCommandResult = {
          operationId,
          eventId,
          status: 'accepted',
          duplicate: false,
        };

        await this.outbox.enqueue(transaction, envelope);
        await this.commandInbox.complete(
          transaction,
          operationId,
          response,
        );

        return response;
      });
    } catch (error) {
      if (
        hasPostgresConstraint(error, 'command_inbox_auth_jti_key')
        || hasPostgresConstraint(error, 'command_inbox_auth_jti')
      ) {
        throw new ApplicationError(
          'AUTH_REPLAYED',
          'The service assertion has already authorized another command',
          409,
          false,
        );
      }

      throw error;
    }
  }
}

function hashJson(value: unknown): string {
  return createHash('sha256').update(JSON.stringify(value)).digest('hex');
}

function isNoopResponse(value: JsonValue | null): boolean {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) {
    return false;
  }

  const response = value as Readonly<Record<string, JsonValue>>;

  return (
    typeof response['operationId'] === 'string'
    && typeof response['eventId'] === 'string'
    && response['status'] === 'accepted'
    && typeof response['duplicate'] === 'boolean'
  );
}

function hasPostgresConstraint(error: unknown, constraint: string): boolean {
  if (typeof error !== 'object' || error === null) {
    return false;
  }

  const candidate = error as { readonly code?: unknown; readonly constraint?: unknown };

  return candidate.code === '23505'
    && typeof candidate.constraint === 'string'
    && candidate.constraint.includes(constraint);
}
