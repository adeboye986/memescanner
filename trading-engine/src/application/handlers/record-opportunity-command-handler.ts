import type { Kysely } from 'kysely';

import type { RecordOpportunityCommand } from '../commands/record-opportunity-command.js';
import type { EventEnvelope } from '../../contracts/events/event-envelope.schema.js';
import {
  ETHEREUM_MAINNET_ID,
  SOLANA_MAINNET_ID,
  type OpportunityCommandResponse,
} from '../../contracts/http/opportunity-command.schema.js';
import type { Database, JsonValue } from '../../infrastructure/database/client.js';
import type { CommandInboxRepository } from '../../infrastructure/database/repositories/command-inbox-repository.js';
import type { OpportunityRepository } from '../../infrastructure/database/repositories/opportunity-repository.js';
import type { OutboxRepository } from '../../infrastructure/database/repositories/outbox-repository.js';
import { ApplicationError } from '../../shared/errors/application-error.js';
import { newEngineId, type EngineId } from '../../shared/ids/id.js';
import { hashCanonicalJson } from '../../shared/json/canonical-json.js';

const ethereumAddressPattern = /^0x[0-9a-f]{40}$/;
const solanaAddressPattern = /^[1-9A-HJ-NP-Za-km-z]{32,44}$/;
const base58Alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

export class RecordOpportunityCommandHandler {
  public constructor(
    private readonly database: Kysely<Database>,
    private readonly commandInbox: CommandInboxRepository,
    private readonly opportunities: OpportunityRepository,
    private readonly outbox: OutboxRepository,
    private readonly createId: () => EngineId = newEngineId,
  ) {}

  public async execute(
    command: RecordOpportunityCommand,
  ): Promise<OpportunityCommandResponse> {
    assertNetworkAddress(command.body.network.id, command.body.asset.address);
    assertSourceSemantics(command.body);
    const requestHash = hashCanonicalJson({
      command: 'opportunity.record',
      body: command.body,
    });
    const acceptedRequestHash = hashCanonicalJson(command.body);

    try {
      return await this.database.transaction().execute(async (transaction) => {
        const operationId = this.createId();
        const inserted = await this.commandInbox.insert(transaction, {
          id: operationId,
          idempotencyKey: command.idempotencyKey,
          authJti: command.authJti,
          subject: command.subject,
          commandName: 'opportunity.record',
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
            existing?.request_hash !== requestHash
            || existing.subject !== command.subject
            || existing.command_name !== 'opportunity.record'
          ) {
            throw new ApplicationError(
              'IDEMPOTENCY_CONFLICT',
              'The idempotency key was already used for another request',
              409,
              false,
            );
          }

          if (existing.status !== 'succeeded' || !isOpportunityResponse(existing.response)) {
            throw new ApplicationError(
              'COMMAND_IN_PROGRESS',
              'The command is still being processed',
              409,
              true,
            );
          }

          return {
            ...(existing.response as unknown as OpportunityCommandResponse),
            duplicate: true,
          };
        }

        const opportunityId = this.createId();
        const eventId = this.createId();
        await this.opportunities.insert(
          transaction,
          opportunityId,
          command.body,
          acceptedRequestHash,
        );

        const payload = {
          operation_id: operationId,
          opportunity_id: opportunityId,
          ...command.body,
        };
        const envelope: EventEnvelope = {
          event_id: eventId,
          event_type: 'opportunity.recorded.v1',
          schema_version: 1,
          occurred_at: new Date().toISOString(),
          producer: 'trading-engine',
          aggregate_type: 'opportunity',
          aggregate_id: opportunityId,
          aggregate_version: 1,
          correlation_id: command.correlationId,
          causation_id: operationId,
          idempotency_key: command.idempotencyKey,
          traceparent: command.traceparent,
          payload,
          payload_sha256: hashCanonicalJson(payload),
        };
        const response: OpportunityCommandResponse = {
          operationId,
          opportunityId,
          eventId,
          status: 'accepted',
          duplicate: false,
        };

        await this.outbox.enqueue(transaction, envelope);
        await this.commandInbox.complete(transaction, operationId, response);

        return response;
      });
    } catch (error) {
      if (hasPostgresConstraint(error, 'opportunities_source_unique')) {
        throw new ApplicationError(
          'OPPORTUNITY_SOURCE_CONFLICT',
          'The source opportunity has already been recorded',
          409,
          false,
        );
      }

      if (hasPostgresConstraint(error, 'opportunities_user_discovery_unique')) {
        throw new ApplicationError(
          'OPPORTUNITY_DISCOVERY_CONFLICT',
          'The user discovery opportunity has already been recorded',
          409,
          false,
        );
      }

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

function assertNetworkAddress(networkId: string, address: string): void {
  const valid = networkId === ETHEREUM_MAINNET_ID
    ? ethereumAddressPattern.test(address)
    : networkId === SOLANA_MAINNET_ID && isSolanaAddress(address);

  if (!valid) {
    throw new ApplicationError(
      'VALIDATION_FAILED',
      'The asset address does not match the selected network',
      400,
      false,
    );
  }
}

export function isSolanaAddress(address: string): boolean {
  if (!solanaAddressPattern.test(address)) {
    return false;
  }

  let value = 0n;

  for (const character of address) {
    value = value * 58n + BigInt(base58Alphabet.indexOf(character));
  }

  const leadingZeroBytes = /^1*/.exec(address)?.[0].length ?? 0;
  const encodedBytes = value === 0n
    ? 0
    : Math.ceil(value.toString(16).length / 2);

  return leadingZeroBytes + encodedBytes === 32;
}

function assertSourceSemantics(command: RecordOpportunityCommand['body']): void {
  const volume = command.market_snapshot.volume_usd;
  const requiresDexscreener = command.network.id === ETHEREUM_MAINNET_ID
    || command.source.scanner === 'momentum';
  const measurements = [
    command.market_snapshot.price_usd,
    command.market_snapshot.market_cap_usd,
    command.market_snapshot.liquidity_usd,
  ];

  const invalidMeasurementProvider = requiresDexscreener
    && measurements.some((measurement) =>
      measurement !== undefined && measurement.provider !== 'dexscreener',
    );
  const invalidVolume = volume !== undefined && (
    command.network.id === SOLANA_MAINNET_ID && command.source.scanner === 'new-token'
      ? volume.provider !== 'birdeye' || volume.window !== '1m'
      : volume.provider !== 'dexscreener' || volume.window !== '5m'
  );

  if (invalidMeasurementProvider || invalidVolume) {
    throw new ApplicationError(
      'VALIDATION_FAILED',
      'The market snapshot provenance does not match the scanner contract',
      400,
      false,
    );
  }
}

function isOpportunityResponse(value: JsonValue | null): boolean {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) {
    return false;
  }

  const response = value as Readonly<Record<string, JsonValue>>;

  return typeof response['operationId'] === 'string'
    && typeof response['opportunityId'] === 'string'
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
