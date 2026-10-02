import type { Kysely } from 'kysely';

import type { RecordPaperPositionCommand } from '../commands/record-paper-position-command.js';
import type { PaperPositionRegistrationResponse } from '../../contracts/http/paper-position-command.schema.js';
import { assertPaperStrategy, PAPER_LIFECYCLE_POLICY_KEY, PAPER_LIFECYCLE_POLICY_VERSION } from '../../domain/paper/paper-position-lifecycle.js';
import type { Database, JsonValue } from '../../infrastructure/database/client.js';
import type { CommandInboxRepository } from '../../infrastructure/database/repositories/command-inbox-repository.js';
import type { OutboxRepository } from '../../infrastructure/database/repositories/outbox-repository.js';
import type { PaperPositionLifecycleRepository } from '../../infrastructure/database/repositories/paper-position-lifecycle-repository.js';
import type { EventEnvelope } from '../../contracts/events/event-envelope.schema.js';
import { ApplicationError } from '../../shared/errors/application-error.js';
import { newEngineId, type EngineId } from '../../shared/ids/id.js';
import { hashCanonicalJson } from '../../shared/json/canonical-json.js';

export class RecordPaperPositionCommandHandler {
  public constructor(
    private readonly database: Kysely<Database>,
    private readonly commandInbox: CommandInboxRepository,
    private readonly positions: PaperPositionLifecycleRepository,
    private readonly outbox: OutboxRepository,
    private readonly createId: () => EngineId = newEngineId,
  ) {}

  public async execute(command: RecordPaperPositionCommand): Promise<PaperPositionRegistrationResponse> {
    try {
      assertPaperStrategy(command.body.strategy);
    } catch {
      throw new ApplicationError('VALIDATION_FAILED', 'The PAPER strategy snapshot is invalid', 400, false);
    }

    const requestHash = hashCanonicalJson({ command: 'paper.position.record', body: command.body });

    try {
      return await this.database.transaction().execute(async (transaction) => {
      const operationId = this.createId();
      const inserted = await this.commandInbox.insert(transaction, {
        id: operationId,
        idempotencyKey: command.idempotencyKey,
        authJti: command.authJti,
        subject: command.subject,
        commandName: 'paper.position.record',
        requestHash,
        correlationId: command.correlationId,
        traceparent: command.traceparent,
      });

      if (inserted === undefined) {
        const existing = await this.commandInbox.findByIdempotencyKey(transaction, command.idempotencyKey);

        if (existing?.request_hash !== requestHash
          || existing.subject !== command.subject
          || existing.command_name !== 'paper.position.record') {
          throw new ApplicationError('IDEMPOTENCY_CONFLICT', 'The idempotency key was already used for another request', 409, false);
        }

        if (existing.status !== 'succeeded' || !isRegistrationResponse(existing.response)) {
          throw new ApplicationError('COMMAND_IN_PROGRESS', 'The command is still being processed', 409, true);
        }

        return { ...(existing.response as unknown as PaperPositionRegistrationResponse), duplicate: true };
      }

      const opportunity = await transaction
        .selectFrom('opportunities')
        .select(['id', 'source_opportunity_id', 'control_plane_user_id', 'network_id', 'asset_address'])
        .where('id', '=', command.body.source.engine_opportunity_id)
        .executeTakeFirst();

      if (opportunity?.source_opportunity_id !== command.body.source.trade_opportunity_id
        || opportunity.control_plane_user_id !== command.body.subject.control_plane_user_id
        || opportunity.network_id !== command.body.network.id
        || opportunity.asset_address !== command.body.asset.address) {
        throw new ApplicationError('PAPER_POSITION_CORRELATION_INVALID', 'The PAPER position does not match its opportunity', 409, false);
      }

      const prior = await this.positions.findBySourcePosition(transaction, command.body.source.paper_position_id);

      if (prior !== undefined) {
        throw new ApplicationError('PAPER_POSITION_REGISTRATION_CONFLICT', 'The PAPER position was already registered differently', 409, false);
      }

      const positionId = this.createId();
      const eventId = this.createId();
      await this.positions.insert(transaction, positionId, command.body, hashCanonicalJson(command.body.strategy));
      const payload = {
        operation_id: operationId,
        position_id: positionId,
        policy: { key: PAPER_LIFECYCLE_POLICY_KEY, version: PAPER_LIFECYCLE_POLICY_VERSION },
        ...command.body,
      };
      const envelope: EventEnvelope = {
        event_id: eventId,
        event_type: 'paper.position.recorded.v1',
        schema_version: 1,
        occurred_at: new Date().toISOString(),
        producer: 'trading-engine',
        aggregate_type: 'paper_position',
        aggregate_id: positionId,
        aggregate_version: 1,
        correlation_id: command.correlationId,
        causation_id: operationId,
        idempotency_key: command.idempotencyKey,
        traceparent: command.traceparent,
        payload,
        payload_sha256: hashCanonicalJson(payload),
      };
      const response: PaperPositionRegistrationResponse = {
        operationId,
        positionId,
        eventId,
        status: 'accepted',
        duplicate: false,
      };

      await this.outbox.enqueue(transaction, envelope);
      await this.commandInbox.complete(transaction, operationId, response);

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

function isRegistrationResponse(value: JsonValue | null): boolean {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) {
    return false;
  }

  const response = value as Readonly<Record<string, JsonValue>>;

  return typeof response['operationId'] === 'string'
    && typeof response['positionId'] === 'string'
    && typeof response['eventId'] === 'string'
    && response['status'] === 'accepted'
    && typeof response['duplicate'] === 'boolean';
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
