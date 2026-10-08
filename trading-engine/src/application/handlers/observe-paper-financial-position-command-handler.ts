import type { Kysely, Transaction } from 'kysely';

import type { ObservePaperFinancialPositionCommand } from '../commands/observe-paper-financial-position-command.js';
import type { PaperFinancialObservationResponse } from '../../contracts/http/paper-financial-observation-command.schema.js';
import type { PaperPositionObservation, PaperPositionRegistration } from '../../contracts/http/paper-position-command.schema.js';
import type { EventEnvelope } from '../../contracts/events/event-envelope.schema.js';
import { evaluatePaperLifecycle } from '../../domain/paper/paper-position-lifecycle.js';
import type { Database, JsonValue } from '../../infrastructure/database/client.js';
import type { CommandInboxRepository } from '../../infrastructure/database/repositories/command-inbox-repository.js';
import type { OutboxRepository } from '../../infrastructure/database/repositories/outbox-repository.js';
import type { PaperFinancialLifecycleRepository } from '../../infrastructure/database/repositories/paper-financial-lifecycle-repository.js';
import type { PaperPositionLifecycleRepository, PaperPositionLifecycleRecord } from '../../infrastructure/database/repositories/paper-position-lifecycle-repository.js';
import {
  addCanonicalDecimals,
  compareCanonicalDecimals,
  multiplyCanonicalDecimals,
  roundCanonicalDecimal,
  subtractCanonicalDecimals,
} from '../../shared/amount/canonical-decimal.js';
import { ApplicationError } from '../../shared/errors/application-error.js';
import { newEngineId, type EngineId } from '../../shared/ids/id.js';
import { hashCanonicalJson } from '../../shared/json/canonical-json.js';

export interface PaperFinancialLifecyclePolicy {
  readonly enabled: boolean;
  readonly observationMaxAgeSeconds: number;
}

export class ObservePaperFinancialPositionCommandHandler {
  public constructor(
    private readonly database: Kysely<Database>,
    private readonly commandInbox: CommandInboxRepository,
    private readonly lifecycles: PaperPositionLifecycleRepository,
    private readonly financials: PaperFinancialLifecycleRepository,
    private readonly outbox: OutboxRepository,
    private readonly policy: PaperFinancialLifecyclePolicy,
    private readonly createId: () => EngineId = newEngineId,
    private readonly now: () => Date = () => new Date(),
  ) {}

  public async execute(
    command: ObservePaperFinancialPositionCommand,
  ): Promise<PaperFinancialObservationResponse> {
    this.assertCommand(command);
    const requestHash = hashCanonicalJson({ command: 'paper.financial-position.observe', body: command.body });

    try {
      return await this.database.transaction().execute(async (transaction) => {
        const operationId = this.createId();
        const inserted = await this.commandInbox.insert(transaction, {
          id: operationId,
          idempotencyKey: command.idempotencyKey,
          authJti: command.authJti,
          subject: command.subject,
          commandName: 'paper.financial-position.observe',
          requestHash,
          correlationId: command.correlationId,
          traceparent: command.traceparent,
        });

        if (inserted === undefined) {
          return this.replay(transaction, command, requestHash);
        }

        const position = await this.financials.lockPosition(transaction, command.body.position_id);
        const lifecycle = await this.lifecycles.lock(transaction, command.body.position_id);

        if (position === undefined
          || lifecycle === undefined
          || position.id !== lifecycle.id
          || lifecycle.source_position_id !== position.id
          || position.control_plane_user_id !== command.body.subject.control_plane_user_id
          || lifecycle.control_plane_user_id !== command.body.subject.control_plane_user_id
          || position.network_id !== command.body.network.id
          || lifecycle.network_id !== command.body.network.id
          || position.asset_address !== command.body.asset.address
          || lifecycle.asset_address !== command.body.asset.address
          || position.strategy_sha256 !== lifecycle.strategy_sha256) {
          throw new ApplicationError(
            'PAPER_FINANCIAL_POSITION_CORRELATION_INVALID',
            'The observation does not match its engine-owned PAPER position',
            409,
            false,
          );
        }

        const prior = await this.lifecycles.findDecision(
          transaction,
          lifecycle.id,
          command.body.source.observation_id,
          command.body.source.sequence,
        );

        if (prior !== undefined) {
          if (prior.request_sha256 !== hashCanonicalJson(command.body)
            || prior.observation_id !== command.body.source.observation_id
            || prior.observation_sequence !== command.body.source.sequence) {
            throw new ApplicationError(
              'PAPER_OBSERVATION_CONFLICT',
              'The observation identity or sequence conflicts with existing evidence',
              409,
              false,
            );
          }

          const settlement = await this.financials.findSettlementByDecision(transaction, prior.id);
          const response: PaperFinancialObservationResponse = {
            operationId,
            positionId: position.id,
            decisionId: prior.id,
            eventId: prior.evaluated_event_id,
            settlementId: settlement?.id ?? null,
            decision: prior.decision,
            duplicate: true,
          };
          await this.commandInbox.complete(transaction, operationId, response);

          return response;
        }

        if (position.state !== 'open' || lifecycle.state !== 'open') {
          throw new ApplicationError(
            'PAPER_FINANCIAL_POSITION_CLOSED',
            'The engine-owned PAPER position is already closed',
            409,
            false,
          );
        }

        if (command.body.source.sequence !== lifecycle.last_observation_sequence + 1) {
          throw new ApplicationError(
            'PAPER_OBSERVATION_OUT_OF_ORDER',
            'The observation sequence is not the next expected value',
            409,
            false,
          );
        }

        const result = evaluatePaperLifecycle({
          entryMarketCap: lifecycle.entry_market_cap_usd,
          peakMarketCap: lifecycle.peak_market_cap_usd,
          protectionState: lifecycle.protection_state,
          terminal: false,
          strategy: lifecycle.strategy_snapshot as unknown as PaperPositionRegistration['strategy'],
        }, command.body as unknown as PaperPositionObservation);
        const decisionId = this.createId();
        const eventId = this.createId();
        const occurredAt = this.now();
        const resultPayload = this.resultPayload(command, lifecycle, operationId, decisionId, result);
        const resultSha256 = hashCanonicalJson(resultPayload);
        let settlementId: string | null = null;
        let eventPayload: Readonly<Record<string, unknown>> = resultPayload;

        await this.lifecycles.persistDecision(transaction, {
          decisionId,
          evaluatedEventId: eventId,
          exitEventId: result.decision === 'EXIT' ? eventId : null,
          position: lifecycle,
          observationId: command.body.source.observation_id,
          sequence: command.body.source.sequence,
          requestSha256: hashCanonicalJson(command.body),
          resultSha256,
          result,
          marketCap: command.body.market.market_cap_usd,
          price: command.body.market.price_usd,
          liquidity: command.body.market.liquidity_usd ?? null,
          observedAt: command.body.market.observed_at,
          correlationId: command.correlationId,
          traceparent: command.traceparent,
          evidence: command.body,
        });

        if (result.decision === 'EXIT') {
          const wallet = await this.financials.lockWallet(transaction, position.wallet_id);
          const investedBefore = await this.financials.accountBalance(transaction, wallet.id, 'invested');

          if (compareCanonicalDecimals(investedBefore, position.cost_basis_native) < 0) {
            throw new ApplicationError(
              'PAPER_FINANCIAL_STATE_INVALID',
              'The PAPER ledger cannot release the position cost basis',
              409,
              false,
            );
          }

          const proceedsNative = roundCanonicalDecimal(
            multiplyCanonicalDecimals(position.cost_basis_native, result.observedMultiple),
            30,
          );
          const realizedPnlNative = subtractCanonicalDecimals(proceedsNative, position.cost_basis_native);

          if (compareCanonicalDecimals(proceedsNative, '0') <= 0) {
            throw new ApplicationError(
              'PAPER_FINANCIAL_STATE_INVALID',
              'The PAPER settlement proceeds must be positive',
              409,
              false,
            );
          }

          const ids = this.exitIdentifiers();
          settlementId = ids.settlementId;
          const availableBefore = await this.financials.accountBalance(transaction, wallet.id, 'available');
          const realizedBefore = await this.financials.realizedPnlBalance(transaction, wallet.id);
          const availableAfter = addCanonicalDecimals(availableBefore, proceedsNative);
          const investedAfter = subtractCanonicalDecimals(investedBefore, position.cost_basis_native);
          const realizedAfter = addCanonicalDecimals(realizedBefore, realizedPnlNative);
          eventPayload = {
            ...resultPayload,
            wallet: {
              wallet_id: wallet.id,
              currency: wallet.currency,
              available_balance_native: availableAfter,
              invested_balance_native: investedAfter,
              realized_pnl_native: realizedAfter,
            },
            settlement: {
              settlement_id: ids.settlementId,
              order_id: ids.orderId,
              fill_id: ids.fillId,
              ledger_transaction_id: ids.ledgerTransactionId,
              ledger_transaction_reference: `paper:exit:${position.id}:v1`,
              cost_basis_native: position.cost_basis_native,
              proceeds_native: proceedsNative,
              realized_pnl_native: realizedPnlNative,
              exit_price_usd: command.body.market.price_usd,
              exit_market_cap_usd: command.body.market.market_cap_usd,
              observed_multiple: result.observedMultiple,
              fill_model: 'observed_market_cap_ratio_v1',
              settled_at: occurredAt.toISOString(),
            },
          };
          await this.financials.persistExit(transaction, {
            ids,
            position,
            wallet,
            decisionId,
            eventId,
            command: command.body,
            idempotencyKey: command.idempotencyKey,
            correlationId: command.correlationId,
            traceparent: command.traceparent,
            costBasisNative: position.cost_basis_native,
            proceedsNative,
            realizedPnlNative,
            observedMultiple: result.observedMultiple,
            resultSha256: hashCanonicalJson(eventPayload),
            settledAt: occurredAt,
          });
        }

        const eventType = result.decision === 'EXIT'
          ? 'paper.exit.settled.v1'
          : 'paper.position.held.v1';
        await this.outbox.enqueue(transaction, envelope(
          eventId,
          eventType,
          lifecycle.lifecycle_version + 2,
          operationId,
          command,
          eventPayload,
          occurredAt,
        ));
        const response: PaperFinancialObservationResponse = {
          operationId,
          positionId: position.id,
          decisionId,
          eventId,
          settlementId,
          decision: result.decision,
          duplicate: false,
        };
        await this.commandInbox.complete(transaction, operationId, response);

        return response;
      });
    } catch (error) {
      if (hasPostgresConstraint(error, 'command_inbox_auth_jti')) {
        throw new ApplicationError('AUTH_REPLAYED', 'The service assertion has already authorized another command', 409, false);
      }

      if (hasPostgresConstraint(error, 'paper_exit_settlements_position_id')) {
        throw new ApplicationError('PAPER_FINANCIAL_POSITION_CLOSED', 'The PAPER position is already settled', 409, false);
      }

      throw error;
    }
  }

  private assertCommand(command: ObservePaperFinancialPositionCommand): void {
    if (!this.policy.enabled) {
      throw new ApplicationError('CAPABILITY_UNAVAILABLE', 'Engine PAPER financial lifecycle is disabled', 409, false);
    }

    const now = this.now().getTime();
    const observedAt = new Date(command.body.market.observed_at).getTime();
    const fetchedAt = new Date(command.body.market.fetched_at).getTime();
    const maximumAge = this.policy.observationMaxAgeSeconds * 1_000;

    if (!Number.isFinite(observedAt)
      || !Number.isFinite(fetchedAt)
      || observedAt > now + 30_000
      || fetchedAt > now + 30_000
      || now - observedAt > maximumAge
      || now - fetchedAt > maximumAge
      || fetchedAt < observedAt - maximumAge
      || compareCanonicalDecimals(command.body.market.market_cap_usd, '0') <= 0
      || compareCanonicalDecimals(command.body.market.price_usd, '0') <= 0) {
      throw new ApplicationError(
        'PAPER_OBSERVATION_INVALID',
        'The PAPER market observation is stale or invalid',
        409,
        false,
      );
    }
  }

  private async replay(
    transaction: Transaction<Database>,
    command: ObservePaperFinancialPositionCommand,
    requestHash: string,
  ): Promise<PaperFinancialObservationResponse> {
    const existing = await this.commandInbox.findByIdempotencyKey(transaction, command.idempotencyKey);

    if (existing?.request_hash !== requestHash
      || existing.subject !== command.subject
      || existing.command_name !== 'paper.financial-position.observe') {
      throw new ApplicationError('IDEMPOTENCY_CONFLICT', 'The idempotency key was already used for another request', 409, false);
    }

    if (existing.status !== 'succeeded' || !isResponse(existing.response)) {
      throw new ApplicationError('COMMAND_IN_PROGRESS', 'The command is still being processed', 409, true);
    }

    return { ...(existing.response as unknown as PaperFinancialObservationResponse), duplicate: true };
  }

  private resultPayload(
    command: ObservePaperFinancialPositionCommand,
    lifecycle: PaperPositionLifecycleRecord,
    operationId: string,
    decisionId: string,
    result: ReturnType<typeof evaluatePaperLifecycle>,
  ): Readonly<Record<string, unknown>> {
    return {
      operation_id: operationId,
      position_id: lifecycle.id,
      decision_id: decisionId,
      source: command.body.source,
      subject: command.body.subject,
      network: command.body.network,
      asset: command.body.asset,
      policy: { key: lifecycle.policy_key, version: lifecycle.policy_version },
      lifecycle_version: lifecycle.lifecycle_version + 1,
      decision: result.decision,
      exit_type: result.exitType,
      market: command.body.market,
      observed_multiple: result.observedMultiple,
      trigger_multiple: result.triggerMultiple,
      peak_market_cap_usd: result.peakMarketCap,
      peak_multiple: result.peakMultiple,
      drawdown_percent: result.drawdownPercent,
      protection_before: result.protectionBefore,
      protection_after: result.protectionAfter,
      transitions: result.transitions,
    };
  }

  private exitIdentifiers(): {
    readonly orderId: EngineId;
    readonly fillId: EngineId;
    readonly settlementId: EngineId;
    readonly ledgerTransactionId: EngineId;
    readonly availableEntryId: EngineId;
    readonly investedEntryId: EngineId;
    readonly realizedPnlEntryId: EngineId;
  } {
    return {
      orderId: this.createId(),
      fillId: this.createId(),
      settlementId: this.createId(),
      ledgerTransactionId: this.createId(),
      availableEntryId: this.createId(),
      investedEntryId: this.createId(),
      realizedPnlEntryId: this.createId(),
    };
  }
}

function envelope(
  eventId: string,
  eventType: string,
  aggregateVersion: number,
  causationId: string,
  command: ObservePaperFinancialPositionCommand,
  payload: Readonly<Record<string, unknown>>,
  occurredAt: Date,
): EventEnvelope {
  return {
    event_id: eventId,
    event_type: eventType,
    schema_version: 1,
    occurred_at: occurredAt.toISOString(),
    producer: 'trading-engine',
    aggregate_type: 'paper_position',
    aggregate_id: command.body.position_id,
    aggregate_version: aggregateVersion,
    correlation_id: command.correlationId,
    causation_id: causationId,
    idempotency_key: command.idempotencyKey,
    traceparent: command.traceparent,
    payload,
    payload_sha256: hashCanonicalJson(payload),
  };
}

function isResponse(value: JsonValue | null): boolean {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) {
    return false;
  }

  const response = value as Readonly<Record<string, JsonValue>>;

  return ['operationId', 'positionId', 'decisionId', 'eventId']
    .every((key) => typeof response[key] === 'string')
    && (typeof response['settlementId'] === 'string' || response['settlementId'] === null)
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
