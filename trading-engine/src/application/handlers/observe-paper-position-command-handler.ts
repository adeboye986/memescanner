import type { Kysely } from 'kysely';

import type { ObservePaperPositionCommand } from '../commands/observe-paper-position-command.js';
import type { EventEnvelope } from '../../contracts/events/event-envelope.schema.js';
import type { PaperPositionObservationResponse, PaperPositionRegistration } from '../../contracts/http/paper-position-command.schema.js';
import { evaluatePaperLifecycle } from '../../domain/paper/paper-position-lifecycle.js';
import type { Database, JsonValue } from '../../infrastructure/database/client.js';
import type { CommandInboxRepository } from '../../infrastructure/database/repositories/command-inbox-repository.js';
import type { OutboxRepository } from '../../infrastructure/database/repositories/outbox-repository.js';
import type { PaperPositionLifecycleRepository } from '../../infrastructure/database/repositories/paper-position-lifecycle-repository.js';
import { ApplicationError } from '../../shared/errors/application-error.js';
import { newEngineId, type EngineId } from '../../shared/ids/id.js';
import { hashCanonicalJson } from '../../shared/json/canonical-json.js';

export class ObservePaperPositionCommandHandler {
  public constructor(
    private readonly database: Kysely<Database>,
    private readonly commandInbox: CommandInboxRepository,
    private readonly positions: PaperPositionLifecycleRepository,
    private readonly outbox: OutboxRepository,
    private readonly createId: () => EngineId = newEngineId,
  ) {}

  public async execute(command: ObservePaperPositionCommand): Promise<PaperPositionObservationResponse> {
    const requestHash = hashCanonicalJson({ command: 'paper.position.observe', body: command.body });

    try {
      return await this.database.transaction().execute(async (transaction) => {
      const operationId = this.createId();
      const inserted = await this.commandInbox.insert(transaction, {
        id: operationId,
        idempotencyKey: command.idempotencyKey,
        authJti: command.authJti,
        subject: command.subject,
        commandName: 'paper.position.observe',
        requestHash,
        correlationId: command.correlationId,
        traceparent: command.traceparent,
      });

      if (inserted === undefined) {
        const existing = await this.commandInbox.findByIdempotencyKey(transaction, command.idempotencyKey);

        if (existing?.request_hash !== requestHash
          || existing.subject !== command.subject
          || existing.command_name !== 'paper.position.observe') {
          throw new ApplicationError('IDEMPOTENCY_CONFLICT', 'The idempotency key was already used for another request', 409, false);
        }

        if (existing.status !== 'succeeded' || !isObservationResponse(existing.response)) {
          throw new ApplicationError('COMMAND_IN_PROGRESS', 'The command is still being processed', 409, true);
        }

        return { ...(existing.response as unknown as PaperPositionObservationResponse), duplicate: true };
      }

      const position = await this.positions.lock(transaction, command.body.position_id);

      if (position?.source_position_id !== command.body.source.paper_position_id
        || position.control_plane_user_id !== command.body.subject.control_plane_user_id
        || position.network_id !== command.body.network.id
        || position.asset_address !== command.body.asset.address) {
        throw new ApplicationError('PAPER_POSITION_CORRELATION_INVALID', 'The observation does not match its PAPER position', 409, false);
      }

      const prior = await this.positions.findDecision(
        transaction,
        position.id,
        command.body.source.observation_id,
        command.body.source.sequence,
      );

      if (prior !== undefined) {
        if (prior.request_sha256 !== hashCanonicalJson(command.body)
          || prior.observation_id !== command.body.source.observation_id
          || prior.observation_sequence !== command.body.source.sequence) {
          throw new ApplicationError('PAPER_OBSERVATION_CONFLICT', 'The observation identity or sequence conflicts with existing evidence', 409, false);
        }

        const response: PaperPositionObservationResponse = {
          operationId,
          positionId: position.id,
          decisionId: prior.id,
          eventId: prior.evaluated_event_id,
          decision: prior.decision,
          duplicate: true,
        };
        await this.commandInbox.complete(transaction, operationId, response);

        return response;
      }

      if (position.state === 'terminal') {
        throw new ApplicationError('PAPER_POSITION_TERMINAL', 'The PAPER position lifecycle is terminal', 409, false);
      }

      if (command.body.source.sequence !== position.last_observation_sequence + 1) {
        throw new ApplicationError('PAPER_OBSERVATION_OUT_OF_ORDER', 'The observation sequence is not the next expected value', 409, false);
      }

      const result = evaluatePaperLifecycle({
        entryMarketCap: position.entry_market_cap_usd,
        peakMarketCap: position.peak_market_cap_usd,
        protectionState: position.protection_state,
        terminal: false,
        strategy: position.strategy_snapshot as unknown as PaperPositionRegistration['strategy'],
      }, command.body);
      const decisionId = this.createId();
      const evaluatedEventId = this.createId();
      const exitEventId = result.decision === 'EXIT' ? this.createId() : null;
      const resultPayload = {
        position_id: position.id,
        decision_id: decisionId,
        source: command.body.source,
        subject: command.body.subject,
        network: command.body.network,
        asset: command.body.asset,
        policy: { key: position.policy_key, version: position.policy_version },
        lifecycle_version: position.lifecycle_version + 1,
        decision: result.decision,
        exit_type: result.exitType,
        observed_multiple: result.observedMultiple,
        trigger_multiple: result.triggerMultiple,
        market: command.body.market,
        peak_market_cap_usd: result.peakMarketCap,
        peak_multiple: result.peakMultiple,
        drawdown_percent: result.drawdownPercent,
        protection_before: result.protectionBefore,
        protection_after: result.protectionAfter,
        transitions: result.transitions,
      };
      const resultSha256 = hashCanonicalJson(resultPayload);
      await this.positions.persistDecision(transaction, {
        decisionId,
        evaluatedEventId,
        exitEventId,
        position,
        observationId: command.body.source.observation_id,
        sequence: command.body.source.sequence,
        requestSha256: hashCanonicalJson(command.body),
        resultSha256,
        result,
        marketCap: command.body.market.market_cap_usd,
        price: command.body.market.price_usd ?? null,
        liquidity: command.body.market.liquidity_usd ?? null,
        observedAt: command.body.market.observed_at,
        correlationId: command.correlationId,
        traceparent: command.traceparent,
        evidence: command.body,
      });

      await this.outbox.enqueue(transaction, envelope(
        evaluatedEventId,
        'paper.position.evaluated.v1',
        position.id,
        position.lifecycle_version + 1,
        operationId,
        command,
        resultPayload,
      ));

      if (exitEventId !== null) {
        await this.outbox.enqueue(transaction, envelope(
          exitEventId,
          'paper.exit.requested.v1',
          position.id,
          position.lifecycle_version + 1,
          evaluatedEventId,
          command,
          { ...resultPayload, evaluated_event_id: evaluatedEventId, result_sha256: resultSha256 },
        ));
      }

      const response: PaperPositionObservationResponse = {
        operationId,
        positionId: position.id,
        decisionId,
        eventId: evaluatedEventId,
        decision: result.decision,
        duplicate: false,
      };
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

function envelope(
  eventId: string,
  eventType: string,
  positionId: string,
  version: number,
  causationId: string,
  command: ObservePaperPositionCommand,
  payload: Readonly<Record<string, unknown>>,
): EventEnvelope {
  return {
    event_id: eventId,
    event_type: eventType,
    schema_version: 1,
    occurred_at: new Date().toISOString(),
    producer: 'trading-engine',
    aggregate_type: 'paper_position',
    aggregate_id: positionId,
    aggregate_version: version,
    correlation_id: command.correlationId,
    causation_id: causationId,
    idempotency_key: eventType === 'paper.exit.requested.v1'
      ? `paper:exit:${positionId}:${version}`
      : command.idempotencyKey,
    traceparent: command.traceparent,
    payload,
    payload_sha256: hashCanonicalJson(payload),
  };
}

function isObservationResponse(value: JsonValue | null): boolean {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) {
    return false;
  }

  const response = value as Readonly<Record<string, JsonValue>>;

  return typeof response['operationId'] === 'string'
    && typeof response['positionId'] === 'string'
    && typeof response['decisionId'] === 'string'
    && typeof response['eventId'] === 'string'
    && (response['decision'] === 'HOLD' || response['decision'] === 'EXIT')
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
