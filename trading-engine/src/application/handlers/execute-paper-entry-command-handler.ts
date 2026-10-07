import type { Kysely } from 'kysely';

import type { ExecutePaperEntryCommand } from '../commands/execute-paper-entry-command.js';
import type { PaperEntryCommandResponse } from '../../contracts/http/paper-entry-command.schema.js';
import { OPPORTUNITY_EVALUATION_POLICY_V1 } from '../../domain/opportunities/evaluation-policy.js';
import { assertPaperStrategy, PAPER_LIFECYCLE_POLICY_KEY, PAPER_LIFECYCLE_POLICY_VERSION } from '../../domain/paper/paper-position-lifecycle.js';
import type { Database, JsonValue } from '../../infrastructure/database/client.js';
import type { CommandInboxRepository } from '../../infrastructure/database/repositories/command-inbox-repository.js';
import type { OutboxRepository } from '../../infrastructure/database/repositories/outbox-repository.js';
import type {
  PaperEntryRepository,
  PaperEntryIdentifiers,
} from '../../infrastructure/database/repositories/paper-entry-repository.js';
import { compareCanonicalDecimals, subtractCanonicalDecimals } from '../../shared/amount/canonical-decimal.js';
import { ApplicationError } from '../../shared/errors/application-error.js';
import { newEngineId, type EngineId } from '../../shared/ids/id.js';
import { hashCanonicalJson } from '../../shared/json/canonical-json.js';

export interface PaperEntryPolicy {
  readonly enabled: boolean;
  readonly openingBalanceNative: string;
  readonly entryNotionalNative: string;
  readonly intentMaxAgeSeconds: number;
}

export class ExecutePaperEntryCommandHandler {
  public constructor(
    private readonly database: Kysely<Database>,
    private readonly commandInbox: CommandInboxRepository,
    private readonly entries: PaperEntryRepository,
    private readonly outbox: OutboxRepository,
    private readonly policy: PaperEntryPolicy,
    private readonly createId: () => EngineId = newEngineId,
    private readonly now: () => Date = () => new Date(),
  ) {}

  public async execute(command: ExecutePaperEntryCommand): Promise<PaperEntryCommandResponse> {
    this.assertCommand(command);
    const requestHash = hashCanonicalJson({ command: 'paper.entry.execute', body: command.body });

    try {
      return await this.database.transaction().execute(async (transaction) => {
        const operationId = this.createId();
        const inserted = await this.commandInbox.insert(transaction, {
          id: operationId,
          idempotencyKey: command.idempotencyKey,
          authJti: command.authJti,
          subject: command.subject,
          commandName: 'paper.entry.execute',
          requestHash,
          correlationId: command.correlationId,
          traceparent: command.traceparent,
        });

        if (inserted === undefined) {
          return this.replay(transaction, command, requestHash);
        }

        const context = await transaction
          .selectFrom('opportunities as opportunity')
          .innerJoin('opportunity_evaluations as evaluation', 'evaluation.opportunity_id', 'opportunity.id')
          .select([
            'opportunity.id as opportunity_id',
            'opportunity.source_opportunity_id',
            'opportunity.control_plane_user_id',
            'opportunity.network_id',
            'opportunity.asset_address',
            'opportunity.symbol',
            'opportunity.price_usd',
            'opportunity.market_cap_usd',
            'opportunity.liquidity_usd',
            'evaluation.id as evaluation_id',
            'evaluation.policy_key',
            'evaluation.policy_version',
            'evaluation.outcome',
            'evaluation.result_sha256',
            'evaluation.created_at as evaluation_created_at',
          ])
          .where('opportunity.id', '=', command.body.source.engine_opportunity_id)
          .where('evaluation.id', '=', command.body.source.evaluation_id)
          .executeTakeFirst();

        if (context?.source_opportunity_id !== command.body.source.trade_opportunity_id
          || context.control_plane_user_id !== command.body.subject.control_plane_user_id
          || context.network_id !== command.body.network.id
          || context.asset_address !== command.body.asset.address
          || (context.symbol ?? undefined) !== command.body.asset.symbol) {
          throw new ApplicationError(
            'PAPER_ENTRY_CORRELATION_INVALID',
            'The PAPER entry does not match its source opportunity and evaluation',
            409,
            false,
          );
        }

        if (context.outcome !== 'passed'
          || context.policy_key !== OPPORTUNITY_EVALUATION_POLICY_V1.policy_key
          || context.policy_version !== OPPORTUNITY_EVALUATION_POLICY_V1.policy_version
          || context.result_sha256 !== command.body.source.evaluation_result_sha256) {
          throw new ApplicationError(
            'PAPER_ENTRY_EVALUATION_INVALID',
            'The PAPER entry requires the approved passed evaluation',
            409,
            false,
          );
        }

        if (context.price_usd !== command.body.entry.price_usd
          || context.market_cap_usd !== command.body.entry.market_cap_usd
          || (context.liquidity_usd ?? undefined) !== command.body.entry.liquidity_usd) {
          throw new ApplicationError(
            'PAPER_ENTRY_MARKET_SNAPSHOT_INVALID',
            'The PAPER entry market snapshot does not match the immutable opportunity',
            409,
            false,
          );
        }

        const acceptedAt = this.now();

        if (!(context.evaluation_created_at instanceof Date)
          || context.evaluation_created_at.getTime() > acceptedAt.getTime() + 30_000
          || acceptedAt.getTime() - context.evaluation_created_at.getTime()
            > this.policy.intentMaxAgeSeconds * 1_000) {
          throw new ApplicationError(
            'PAPER_ENTRY_EVALUATION_STALE',
            'The approved opportunity evaluation is stale',
            409,
            false,
          );
        }

        const ids = this.identifiers();
        const wallet = await this.entries.establishWallet(
          transaction,
          command.body,
          ids,
          this.policy.openingBalanceNative,
          command.correlationId,
          acceptedAt,
        );
        await this.entries.lockWallet(transaction, wallet.id);
        const availableBefore = await this.entries.accountBalance(transaction, wallet.id, 'available');

        if (compareCanonicalDecimals(availableBefore, this.policy.entryNotionalNative) < 0) {
          throw new ApplicationError(
            'PAPER_FUNDS_INSUFFICIENT',
            'The engine PAPER wallet has insufficient available funds',
            409,
            false,
          );
        }

        if (await this.entries.findOpenPosition(transaction, wallet.id, command.body.asset.address) !== undefined) {
          throw new ApplicationError(
            'PAPER_POSITION_ALREADY_OPEN',
            'An engine PAPER position is already open for this asset',
            409,
            false,
          );
        }

        const intentSha256 = hashCanonicalJson(command.body);
        const authoritySha256 = hashCanonicalJson(command.body.authority);
        const strategySha256 = hashCanonicalJson(command.body.authority.strategy);
        await this.entries.persistEntry(transaction, {
          command: command.body,
          ids,
          idempotencyKey: command.idempotencyKey,
          correlationId: command.correlationId,
          traceparent: command.traceparent,
          openingBalanceNative: this.policy.openingBalanceNative,
          notionalNative: this.policy.entryNotionalNative,
          intentSha256,
          authoritySha256,
          strategySha256,
          acceptedAt,
        }, wallet);
        const availableAfter = subtractCanonicalDecimals(
          availableBefore,
          this.policy.entryNotionalNative,
        );
        const investedAfter = await this.entries.accountBalance(transaction, wallet.id, 'invested');
        const eventId = this.createId();
        const payload = {
          operation_id: operationId,
          wallet: {
            wallet_id: wallet.id,
            currency: 'SOL',
            opening_balance_native: wallet.opening_balance_native,
            available_balance_native: availableAfter,
            invested_balance_native: investedAfter,
          },
          intent: {
            intent_id: ids.intentId,
            intent_sha256: intentSha256,
            authority_sha256: authoritySha256,
            notional_native: this.policy.entryNotionalNative,
          },
          order: {
            order_id: ids.orderId,
            side: 'buy',
            order_type: 'simulated_market',
            status: 'filled',
          },
          fill: {
            fill_id: ids.fillId,
            notional_native: this.policy.entryNotionalNative,
            fee_native: '0',
            fill_price_usd: command.body.entry.price_usd,
            quantity: '1',
            quantity_unit: 'normalized_position_unit',
            fill_model: 'observed_mark_normalized_notional_v1',
            executed_at: acceptedAt.toISOString(),
          },
          position: {
            position_id: ids.positionId,
            state: 'open',
            cost_basis_native: this.policy.entryNotionalNative,
            entry_market_cap_usd: command.body.entry.market_cap_usd,
            entry_price_usd: command.body.entry.price_usd,
            ...(command.body.entry.liquidity_usd === undefined
              ? {}
              : { entry_liquidity_usd: command.body.entry.liquidity_usd }),
            lifecycle: {
              policy_key: PAPER_LIFECYCLE_POLICY_KEY,
              policy_version: PAPER_LIFECYCLE_POLICY_VERSION,
              lifecycle_version: 0,
              state: 'open',
            },
          },
          source: command.body.source,
          subject: command.body.subject,
          network: command.body.network,
          asset: command.body.asset,
          authority: command.body.authority,
          ledger: {
            opening_transaction_reference: wallet.opening_reference,
            entry_transaction_id: ids.entryTransactionId,
            entry_transaction_reference: `paper:entry:${command.body.source.engine_opportunity_id}:v1`,
          },
        };
        await this.outbox.enqueue(transaction, {
          event_id: eventId,
          event_type: 'paper.entry.executed.v1',
          schema_version: 1,
          occurred_at: acceptedAt.toISOString(),
          producer: 'trading-engine',
          aggregate_type: 'paper_position',
          aggregate_id: ids.positionId,
          aggregate_version: 1,
          correlation_id: command.correlationId,
          causation_id: operationId,
          idempotency_key: command.idempotencyKey,
          traceparent: command.traceparent,
          payload,
          payload_sha256: hashCanonicalJson(payload),
        });
        const response: PaperEntryCommandResponse = {
          operationId,
          walletId: wallet.id,
          intentId: ids.intentId,
          orderId: ids.orderId,
          fillId: ids.fillId,
          positionId: ids.positionId,
          eventId,
          status: 'accepted',
          duplicate: false,
        };
        await this.commandInbox.complete(transaction, operationId, response);

        return response;
      });
    } catch (error) {
      if (hasPostgresConstraint(error, 'command_inbox_auth_jti')) {
        throw new ApplicationError('AUTH_REPLAYED', 'The service assertion has already authorized another command', 409, false);
      }

      if (hasPostgresConstraint(error, 'paper_positions_open_asset_unique')) {
        throw new ApplicationError('PAPER_POSITION_ALREADY_OPEN', 'An engine PAPER position is already open for this asset', 409, false);
      }

      if (hasPostgresConstraint(error, 'paper_entry_intents')) {
        throw new ApplicationError('PAPER_ENTRY_CONFLICT', 'The opportunity or evaluation already has another PAPER entry', 409, false);
      }

      throw error;
    }
  }

  private assertCommand(command: ExecutePaperEntryCommand): void {
    if (!this.policy.enabled) {
      throw new ApplicationError('CAPABILITY_UNAVAILABLE', 'Engine PAPER entry is disabled', 409, false);
    }

    try {
      assertPaperStrategy(command.body.authority.strategy);
    } catch {
      throw new ApplicationError('VALIDATION_FAILED', 'The PAPER strategy snapshot is invalid', 400, false);
    }

    if (compareCanonicalDecimals(command.body.entry.requested_notional_native, '0') <= 0
      || compareCanonicalDecimals(command.body.entry.price_usd, '0') <= 0
      || compareCanonicalDecimals(command.body.entry.market_cap_usd, '0') <= 0
      || command.body.entry.requested_notional_native !== this.policy.entryNotionalNative
      || command.body.authority.risk.trade_size_native !== this.policy.entryNotionalNative) {
      throw new ApplicationError('PAPER_ENTRY_POLICY_MISMATCH', 'The PAPER entry does not match the active financial policy', 409, false);
    }

    const now = this.now();
    const createdAt = new Date(command.body.entry.intent_created_at);
    const expiresAt = new Date(command.body.entry.expires_at);
    const maximumExpiry = createdAt.getTime() + this.policy.intentMaxAgeSeconds * 1_000;

    if (!Number.isFinite(createdAt.getTime())
      || !Number.isFinite(expiresAt.getTime())
      || createdAt.getTime() > now.getTime() + 30_000
      || expiresAt.getTime() <= now.getTime()
      || expiresAt.getTime() > maximumExpiry) {
      throw new ApplicationError('PAPER_ENTRY_INTENT_STALE', 'The PAPER entry intent is stale or has an invalid lifetime', 409, false);
    }
  }

  private async replay(
    transaction: Parameters<CommandInboxRepository['findByIdempotencyKey']>[0],
    command: ExecutePaperEntryCommand,
    requestHash: string,
  ): Promise<PaperEntryCommandResponse> {
    const existing = await this.commandInbox.findByIdempotencyKey(transaction, command.idempotencyKey);

    if (existing?.request_hash !== requestHash
      || existing.subject !== command.subject
      || existing.command_name !== 'paper.entry.execute') {
      throw new ApplicationError('IDEMPOTENCY_CONFLICT', 'The idempotency key was already used for another request', 409, false);
    }

    if (existing.status !== 'succeeded' || !isPaperEntryResponse(existing.response)) {
      throw new ApplicationError('COMMAND_IN_PROGRESS', 'The command is still being processed', 409, true);
    }

    return { ...(existing.response as unknown as PaperEntryCommandResponse), duplicate: true };
  }

  private identifiers(): PaperEntryIdentifiers {
    return {
      walletId: this.createId(),
      openingTransactionId: this.createId(),
      openingAvailableEntryId: this.createId(),
      openingEquityEntryId: this.createId(),
      intentId: this.createId(),
      orderId: this.createId(),
      fillId: this.createId(),
      positionId: this.createId(),
      entryTransactionId: this.createId(),
      entryAvailableEntryId: this.createId(),
      entryInvestedEntryId: this.createId(),
    };
  }
}

function isPaperEntryResponse(value: JsonValue | null): boolean {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) {
    return false;
  }

  const response = value as Readonly<Record<string, JsonValue>>;

  return ['operationId', 'walletId', 'intentId', 'orderId', 'fillId', 'positionId', 'eventId']
    .every((key) => typeof response[key] === 'string')
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
