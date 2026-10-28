import type { Kysely } from 'kysely';
import { describe, expect, it } from 'vitest';

import { buildApp } from '../../apps/api/src/app.js';
import { AcceptNoopCommandHandler } from '../../src/application/handlers/accept-noop-command-handler.js';
import { RecordOpportunityCommandHandler } from '../../src/application/handlers/record-opportunity-command-handler.js';
import type { Database } from '../../src/infrastructure/database/client.js';
import { CommandInboxRepository } from '../../src/infrastructure/database/repositories/command-inbox-repository.js';
import { OpportunityRepository } from '../../src/infrastructure/database/repositories/opportunity-repository.js';
import { OutboxRepository } from '../../src/infrastructure/database/repositories/outbox-repository.js';
import {
  TokenScanRepository,
  type TokenScanCandidatePage,
} from '../../src/infrastructure/database/repositories/token-scan-repository.js';
import {
  createServiceToken,
  createTestIdentity,
} from '../support/test-environment.js';

const scanId = '01K9ABCDEFGHJKMNPQRSTVWXYZ';

describe('token scan candidate read contract', () => {
  it('requires the dedicated read scope before query validation', async () => {
    const { app, identity } = createApp();
    const missing = await app.inject({
      method: 'GET',
      url: `/v1/token-scans/${scanId}/candidates`,
    });
    expect(missing.statusCode).toBe(401);

    const wrongToken = await createServiceToken(identity, { scopes: ['commands:token-scans:create'] });
    const denied = await app.inject({
      method: 'GET',
      url: `/v1/token-scans/${scanId}/candidates?control_plane_user_id=7&page=1&per_page=20`,
      headers: { authorization: `Bearer ${wrongToken}` },
    });
    expect(denied.statusCode).toBe(403);
    expect(denied.json()).toMatchObject({ error: { code: 'AUTH_SCOPE_DENIED' } });

    await app.close();
  });

  it('returns only the safe bounded projection for the owning control-plane user', async () => {
    const { app, identity } = createApp();
    const token = await createServiceToken(identity, { scopes: ['token-scans:candidates:read'] });
    const response = await app.inject({
      method: 'GET',
      url: `/v1/token-scans/${scanId}/candidates?control_plane_user_id=7&page=1&per_page=20`,
      headers: { authorization: `Bearer ${token}` },
    });

    expect(response.statusCode).toBe(200);
    expect(response.json()).toEqual({
      schema_version: 1,
      scan_execution_id: scanId,
      control_plane_user_id: '7',
      candidates: [{
        address: 'SyntheticTokenAddress',
        symbol: 'SYN',
        name: 'Synthetic Token',
        provider: 'birdeye',
        state: 'failed',
        reason_code: 'PROVIDER_RATE_LIMITED',
        market: {
          market_cap_usd: 10_000,
          price_usd: 0.0001,
          liquidity_usd: 500,
          volume_5m_usd: null,
          holders: 42,
        },
        security_status: 'unavailable',
        opportunity_id: null,
        evaluated_at: '2026-10-28T09:40:00.000Z',
      }],
      pagination: { page: 1, per_page: 20, total: 1, last_page: 1 },
    });
    expect(response.body).not.toContain('market_snapshot');
    expect(response.body).not.toContain('rawProviderBody');

    await app.close();
  });

  it('returns not found when the requested scan is not owned by the supplied user', async () => {
    const { app, identity } = createApp();
    const token = await createServiceToken(identity, { scopes: ['token-scans:candidates:read'] });
    const response = await app.inject({
      method: 'GET',
      url: `/v1/token-scans/${scanId}/candidates?control_plane_user_id=8&page=1&per_page=20`,
      headers: { authorization: `Bearer ${token}` },
    });

    expect(response.statusCode).toBe(404);
    expect(response.json()).toMatchObject({ error: { code: 'TOKEN_SCAN_NOT_FOUND' } });
    await app.close();
  });

  it('rejects pages outside the documented bounds', async () => {
    const { app, identity } = createApp();
    const token = await createServiceToken(identity, { scopes: ['token-scans:candidates:read'] });
    const response = await app.inject({
      method: 'GET',
      url: `/v1/token-scans/${scanId}/candidates?control_plane_user_id=7&page=1&per_page=51`,
      headers: { authorization: `Bearer ${token}` },
    });

    expect(response.statusCode).toBe(400);
    expect(response.json()).toMatchObject({ error: { code: 'VALIDATION_FAILED' } });
    await app.close();
  });
});

class CandidateRepositoryStub extends TokenScanRepository {
  public override paginateCandidateDiagnostics(
    _database: Kysely<Database>,
    requestedScanId: string,
    controlPlaneUserId: string,
    page: number,
    perPage: number,
  ): Promise<TokenScanCandidatePage | null> {
    if (requestedScanId !== scanId
      || controlPlaneUserId !== '7'
      || page !== 1
      || perPage !== 20) {
      return Promise.resolve(null);
    }

    return Promise.resolve({
      execution: { id: scanId, control_plane_user_id: '7' },
      candidates: [{
        id: '01K9ABCDEFGHJKMNPQRSTVWXY0',
        scan_execution_id: scanId,
        network_id: 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp',
        asset_address: 'SyntheticTokenAddress',
        symbol: 'SYN',
        name: 'Synthetic Token',
        provider: 'birdeye',
        discovery_index: 0,
        state: 'failed',
        reason_code: 'PROVIDER_RATE_LIMITED',
        market_snapshot: {
          marketCap: 10_000,
          price: 0.0001,
          liquidity: 500,
          holders: 42,
          rawProviderBody: 'must-not-leak',
        },
        security_snapshot: { available: false, privateReasons: ['must-not-leak'] },
        qualification_snapshot: null,
        opportunity_id: null,
        created_at: new Date('2026-10-28T09:40:00.000Z'),
        updated_at: new Date('2026-10-28T09:40:00.000Z'),
      }],
      total: 1,
    });
  }
}

function createApp(): {
  readonly app: ReturnType<typeof buildApp>;
  readonly identity: ReturnType<typeof createTestIdentity>;
} {
  const identity = createTestIdentity();
  const database = {} as Kysely<Database>;
  const commandInbox = new CommandInboxRepository();
  const outbox = new OutboxRepository();

  return {
    identity,
    app: buildApp({
      config: identity.config,
      database,
      noopHandler: new AcceptNoopCommandHandler(database, commandInbox, outbox),
      opportunityHandler: new RecordOpportunityCommandHandler(
        database,
        commandInbox,
        new OpportunityRepository(),
        outbox,
      ),
      tokenScanRepository: new CandidateRepositoryStub(),
    }),
  };
}
