import { sql, type Kysely } from 'kysely';
import {
  afterAll,
  beforeAll,
  beforeEach,
  describe,
  expect,
  it,
} from 'vitest';

import { buildApp } from '../../apps/api/src/app.js';
import type { RecordOpportunityCommand } from '../../src/application/commands/record-opportunity-command.js';
import { AcceptNoopCommandHandler } from '../../src/application/handlers/accept-noop-command-handler.js';
import { RecordOpportunityCommandHandler } from '../../src/application/handlers/record-opportunity-command-handler.js';
import {
  ETHEREUM_MAINNET_ID,
  SOLANA_MAINNET_ID,
  type OpportunityCommand,
  type OpportunityCommandResponse,
} from '../../src/contracts/http/opportunity-command.schema.js';
import { createDatabase, type Database } from '../../src/infrastructure/database/client.js';
import { CommandInboxRepository } from '../../src/infrastructure/database/repositories/command-inbox-repository.js';
import { OpportunityRepository } from '../../src/infrastructure/database/repositories/opportunity-repository.js';
import { OutboxRepository } from '../../src/infrastructure/database/repositories/outbox-repository.js';
import {
  createServiceToken,
  createTestIdentity,
  resetDatabase,
  startTestEnvironment,
  stopTestEnvironment,
  type TestEnvironment,
  type TestIdentity,
} from '../support/test-environment.js';

const solanaAddress = 'So11111111111111111111111111111111111111112';
const ethereumAddress = '0x1111111111111111111111111111111111111111';
const traceparent = '00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01';

interface InjectResult {
  readonly statusCode: number;
  json(): unknown;
}

describe('opportunity record command', () => {
  let environment: TestEnvironment;
  let identity: TestIdentity;
  let database: Kysely<Database>;
  let handler: RecordOpportunityCommandHandler;
  let app: Awaited<ReturnType<typeof buildApp>>;

  beforeAll(async () => {
    environment = await startTestEnvironment();
    identity = createTestIdentity(environment, {
      REDIS_URL: 'redis://127.0.0.1:1/15',
    });
    database = createDatabase(identity.config);
    const commandInbox = new CommandInboxRepository();
    const outbox = new OutboxRepository();
    handler = new RecordOpportunityCommandHandler(
      database,
      commandInbox,
      new OpportunityRepository(),
      outbox,
    );
    app = buildApp({
      config: identity.config,
      database,
      noopHandler: new AcceptNoopCommandHandler(database, commandInbox, outbox),
      opportunityHandler: handler,
    });
  });

  beforeEach(async () => {
    await resetDatabase(database);
  });

  afterAll(async () => {
    await app.close();
    await database.destroy();
    await stopTestEnvironment(environment);
  });

  it.each([
    ['Solana new-token', newTokenCommand()],
    ['Solana momentum', momentumCommand()],
    ['Ethereum momentum', ethereumCommand()],
  ])('accepts a valid %s opportunity', async (_name, body) => {
    const response = await send(body, `valid-${body.source.opportunity_id}`);

    expect(response.statusCode).toBe(202);
    expect(response.json()).toMatchObject({ status: 'accepted', duplicate: false });
    expect(await count('opportunities')).toBe(1);
    expect(await count('command_inbox')).toBe(1);
    expect(await count('event_outbox')).toBe(1);
  });

  it('accepts omitted market and security fields without inventing values', async () => {
    const body = newTokenCommand();
    const minimal: OpportunityCommand = {
      schema_version: 1,
      source: body.source,
      subject: body.subject,
      network: body.network,
      asset: { address: body.asset.address },
      market_snapshot: {},
      qualification: { qualified_at: body.qualification.qualified_at },
    };

    const response = await send(minimal, 'minimal-opportunity');
    const stored = await database.selectFrom('opportunities').selectAll().executeTakeFirstOrThrow();

    expect(response.statusCode).toBe(202);
    expect(stored.price_usd).toBeNull();
    expect(stored.security).toBeNull();
  });

  it('rejects additional fields without writing any state', async () => {
    const response = await send(
      { ...newTokenCommand(), execution_intent: 'buy' },
      'unexpected-field',
    );

    expect(response.statusCode).toBe(400);
    expect(response.json()).toMatchObject({ error: { code: 'VALIDATION_FAILED' } });
    expect(await count('command_inbox')).toBe(0);
    expect(await count('opportunities')).toBe(0);
  });

  it('rejects JSON numeric financial values', async () => {
    const body = newTokenCommand() as unknown as Record<string, unknown>;
    const market = body['market_snapshot'] as Record<string, unknown>;
    market['market_cap_usd'] = { value: 12000, provider: 'birdeye' };

    const response = await send(body, 'numeric-financial-value');

    expect(response.statusCode).toBe(400);
    expect(await count('command_inbox')).toBe(0);
  });

  it.each(['1e3', '+1', '01', '1.0', '1,000', 'NaN', 'Infinity', '-0']) (
    'rejects malformed decimal %s',
    async (value) => {
      const body = newTokenCommand() as unknown as Record<string, unknown>;
      const qualification = body['qualification'] as Record<string, unknown>;
      qualification['move_since_discovery_percent'] = value;

      const response = await send(body, `invalid-decimal-${value.replaceAll(/[^a-z0-9]/gi, '-')}`);

      expect(response.statusCode).toBe(400);
      expect(await count('command_inbox')).toBe(0);
    },
  );

  it('rejects unsupported networks at the schema boundary', async () => {
    const body = newTokenCommand() as unknown as Record<string, unknown>;
    body['network'] = { id: 'eip155:8453' };

    const response = await send(body, 'unsupported-network');

    expect(response.statusCode).toBe(400);
    expect(await count('opportunities')).toBe(0);
  });

  it('rejects a network and address mismatch', async () => {
    const body = newTokenCommand();
    const mismatched = {
      ...body,
      network: { id: ETHEREUM_MAINNET_ID },
    } as OpportunityCommand;

    const response = await send(mismatched, 'network-address-mismatch');

    expect(response.statusCode).toBe(400);
    expect(response.json()).toMatchObject({ error: { code: 'VALIDATION_FAILED' } });
    expect(await count('opportunities')).toBe(0);
  });

  it('rejects Base58-looking Solana addresses that do not decode to 32 bytes', async () => {
    const body = newTokenCommand();
    body.asset.address = '2'.repeat(32);

    const response = await send(body, 'invalid-solana-byte-length');

    expect(response.statusCode).toBe(400);
    expect(response.json()).toMatchObject({ error: { code: 'VALIDATION_FAILED' } });
    expect(await count('opportunities')).toBe(0);
  });

  it('requires authentication and the opportunity-create scope', async () => {
    const missing = await app.inject({
      method: 'POST',
      url: '/v1/commands/opportunities',
      headers: { 'idempotency-key': 'missing-auth' },
      payload: newTokenCommand(),
    });
    const wrongScope = await send(newTokenCommand(), 'wrong-scope', ['commands:noop']);

    expect(missing.statusCode).toBe(401);
    expect(wrongScope.statusCode).toBe(403);
    expect(await count('command_inbox')).toBe(0);
  });

  it('returns the original IDs for an idempotent replay with a fresh jti', async () => {
    const body = newTokenCommand();
    const first = await send(body, 'idempotent-opportunity', undefined, 'first-jti');
    const second = await send(body, 'idempotent-opportunity', undefined, 'second-jti');

    const firstResult = first.json() as OpportunityCommandResponse;
    const secondResult = second.json() as OpportunityCommandResponse;

    expect(first.statusCode).toBe(202);
    expect(second.statusCode).toBe(202);
    expect(secondResult).toEqual({ ...firstResult, duplicate: true });
    expect(await count('opportunities')).toBe(1);
    expect(await count('event_outbox')).toBe(1);
  });

  it('returns 409 when one idempotency key is reused for another body', async () => {
    await send(newTokenCommand(), 'same-key-different-body', undefined, 'body-one-jti');
    const changed = newTokenCommand();
    changed.asset.name = 'Changed name';

    const response = await send(
      changed,
      'same-key-different-body',
      undefined,
      'body-two-jti',
    );

    expect(response.statusCode).toBe(409);
    expect(response.json()).toMatchObject({ error: { code: 'IDEMPOTENCY_CONFLICT' } });
    expect(await count('opportunities')).toBe(1);
  });

  it('returns a deterministic conflict for another key with the same source', async () => {
    await send(newTokenCommand(), 'source-first', undefined, 'source-first-jti');

    const response = await send(
      newTokenCommand(),
      'source-second',
      undefined,
      'source-second-jti',
    );

    expect(response.statusCode).toBe(409);
    expect(response.json()).toMatchObject({ error: { code: 'OPPORTUNITY_SOURCE_CONFLICT' } });
    expect(await count('opportunities')).toBe(1);
    expect(await count('event_outbox')).toBe(1);
  });

  it('returns a deterministic conflict for another source with the same user discovery', async () => {
    await send(newTokenCommand(), 'discovery-first', undefined, 'discovery-first-jti');
    const changed = newTokenCommand();
    changed.source.opportunity_id = 'laravel-43';

    const response = await send(
      changed,
      'discovery-second',
      undefined,
      'discovery-second-jti',
    );

    expect(response.statusCode).toBe(409);
    expect(response.json()).toMatchObject({ error: { code: 'OPPORTUNITY_DISCOVERY_CONFLICT' } });
    expect(await count('opportunities')).toBe(1);
  });

  it('uses database constraints for concurrent duplicate attempts', async () => {
    const body = newTokenCommand();
    const results = await Promise.all([
      handler.execute(context(body, 'concurrent-opportunity', 'concurrent-jti-1')),
      handler.execute(context(body, 'concurrent-opportunity', 'concurrent-jti-2')),
      handler.execute(context(body, 'concurrent-opportunity', 'concurrent-jti-3')),
    ]);

    expect(new Set(results.map((result) => result.opportunityId)).size).toBe(1);
    expect(results.filter((result) => result.duplicate)).toHaveLength(2);
    expect(await count('opportunities')).toBe(1);
    expect(await count('event_outbox')).toBe(1);
  });

  it('rejects reusing a JWT jti for another command', async () => {
    await send(newTokenCommand(), 'jti-first', undefined, 'one-use-jti');
    const changed = newTokenCommand();
    changed.source.opportunity_id = 'laravel-44';
    changed.source.discovery_key = 'b'.repeat(64);

    const response = await send(changed, 'jti-second', undefined, 'one-use-jti');

    expect(response.statusCode).toBe(409);
    expect(response.json()).toMatchObject({ error: { code: 'AUTH_REPLAYED' } });
    expect(await count('opportunities')).toBe(1);
  });

  it('rolls back inbox, opportunity, and event when outbox creation fails', async () => {
    class FailingOutboxRepository extends OutboxRepository {
      public override enqueue(): Promise<void> {
        return Promise.reject(new Error('forced outbox failure'));
      }
    }
    const failingHandler = new RecordOpportunityCommandHandler(
      database,
      new CommandInboxRepository(),
      new OpportunityRepository(),
      new FailingOutboxRepository(),
    );

    await expect(
      failingHandler.execute(context(newTokenCommand(), 'forced-rollback', 'rollback-jti')),
    ).rejects.toThrow('forced outbox failure');
    expect(await count('command_inbox')).toBe(0);
    expect(await count('opportunities')).toBe(0);
    expect(await count('event_outbox')).toBe(0);
  });

  it('persists exact strings and emits the exact informational event', async () => {
    const body = momentumCommand();

    const response = await send(body, 'exact-persistence', undefined, 'exact-jti');
    const result = response.json() as OpportunityCommandResponse;
    const stored = await database.selectFrom('opportunities').selectAll().executeTakeFirstOrThrow();
    const outbox = await database.selectFrom('event_outbox').selectAll().executeTakeFirstOrThrow();

    expect(typeof stored.price_usd).toBe('string');
    expect(stored).toMatchObject({
      price_usd: '0.00000125',
      market_cap_usd: '12500',
      liquidity_usd: '3500',
      volume_usd: '1500',
      volume_window: '5m',
      discovery_market_cap_usd: '10000',
      move_since_discovery_percent: '20',
    });
    expect(stored.security).toMatchObject({
      holder_concentration: {
        largest_holder_percent: '12.5',
        top_5_percent: '30',
        top_10_percent: '45.5',
      },
    });
    expect(stored.accepted_request).toEqual(body);
    expect(outbox.envelope).toEqual({
      event_id: result.eventId,
      event_type: 'opportunity.recorded.v1',
      schema_version: 1,
      occurred_at: outbox.envelope.occurred_at,
      producer: 'trading-engine',
      aggregate_type: 'opportunity',
      aggregate_id: result.opportunityId,
      aggregate_version: 1,
      correlation_id: 'opportunity-correlation',
      causation_id: result.operationId,
      idempotency_key: 'exact-persistence',
      traceparent,
      payload: {
        operation_id: result.operationId,
        opportunity_id: result.opportunityId,
        ...body,
      },
      payload_sha256: outbox.envelope.payload_sha256,
    });
    expect(outbox.envelope).not.toHaveProperty('user_id');
    expect(outbox.envelope).not.toHaveProperty('network');
  });

  async function send(
    body: object,
    idempotencyKey: string,
    scopes: readonly string[] = ['commands:opportunities:create'],
    jti = `jti-${idempotencyKey}`,
  ): Promise<InjectResult> {
    const token = await createServiceToken(identity, { scopes, jti });

    return app.inject({
      method: 'POST',
      url: '/v1/commands/opportunities',
      headers: {
        authorization: `Bearer ${token}`,
        'idempotency-key': idempotencyKey,
        'x-correlation-id': 'opportunity-correlation',
        traceparent,
      },
      payload: body,
    });
  }

  async function count(table: 'command_inbox' | 'event_outbox' | 'opportunities'): Promise<number> {
    const result = await database
      .selectFrom(table)
      .select(sql.raw<number>('count(*)::int').as('count'))
      .executeTakeFirstOrThrow();

    return result.count;
  }
});

function context(
  body: OpportunityCommand,
  idempotencyKey: string,
  authJti: string,
): RecordOpportunityCommand {
  return {
    idempotencyKey,
    authJti,
    subject: 'laravel-service',
    correlationId: 'opportunity-correlation',
    traceparent,
    body,
  } as const;
}

function newTokenCommand(): OpportunityCommand {
  return {
    schema_version: 1,
    source: {
      system: 'meme-scanner-laravel',
      opportunity_id: 'laravel-42',
      discovery_key: 'a'.repeat(64),
      scanner: 'new-token',
    },
    subject: { control_plane_user_id: '7' },
    network: { id: SOLANA_MAINNET_ID },
    asset: { address: solanaAddress, symbol: 'MEME', name: 'Meme Token' },
    market_snapshot: {
      price_usd: { value: '0.000001', provider: 'birdeye' },
      market_cap_usd: { value: '12000', provider: 'birdeye' },
      liquidity_usd: { value: '3000.25', provider: 'birdeye' },
      volume_usd: { value: '900.5', provider: 'birdeye', window: '1m' },
    },
    qualification: {
      qualified_at: '2026-09-28T12:00:00.000Z',
      discovery_market_cap_usd: '10000',
      move_since_discovery_percent: '20',
      classification: 'strong',
    },
    security: {
      status: 'passed',
      provider: 'goplus',
      passed: true,
      score: 95,
      risks: [],
      coverage: 'GoPlus Solana token-security evaluation.',
    },
  };
}

function momentumCommand(): OpportunityCommand {
  const command = newTokenCommand();

  return {
    ...command,
    source: { ...command.source, opportunity_id: 'laravel-52', scanner: 'momentum' },
    market_snapshot: {
      price_usd: { value: '0.00000125', provider: 'dexscreener' },
      market_cap_usd: { value: '12500', provider: 'dexscreener' },
      liquidity_usd: { value: '3500', provider: 'dexscreener' },
      volume_usd: { value: '1500', provider: 'dexscreener', window: '5m' },
      pair: { address: '9xQeWvG816bUx9EPjHmaT23yvVMV84LkQg21qZC5YQ9', dex: 'raydium', provider: 'dexscreener' },
    },
    security: {
      status: 'passed',
      provider: 'solana_rpc_holder_analysis',
      passed: true,
      score: 80,
      risks: [],
      holder_concentration: {
        largest_holder_percent: '12.5',
        top_5_percent: '30',
        top_10_percent: '45.5',
        risk_level: 'low',
      },
    },
  };
}

function ethereumCommand(): OpportunityCommand {
  const command = newTokenCommand();

  return {
    ...command,
    source: { ...command.source, opportunity_id: 'laravel-62', scanner: 'momentum' },
    network: { id: ETHEREUM_MAINNET_ID },
    asset: { address: ethereumAddress, symbol: 'ETHM' },
    market_snapshot: {
      price_usd: { value: '0.0002', provider: 'dexscreener' },
      market_cap_usd: { value: '25000', provider: 'dexscreener' },
      volume_usd: { value: '5000', provider: 'dexscreener', window: '5m' },
      pair: {
        address: '0x2222222222222222222222222222222222222222',
        provider: 'dexscreener',
      },
    },
    qualification: {
      qualified_at: '2026-09-28T12:00:00.000Z',
      discovery_market_cap_usd: '25000',
      move_since_discovery_percent: '0',
    },
    security: {
      status: 'unavailable',
      coverage: 'No Ethereum token-security provider is configured.',
      market_validation: {
        provider: 'dexscreener',
        requested_token_is_base: true,
        pair_available: true,
      },
    },
  };
}
