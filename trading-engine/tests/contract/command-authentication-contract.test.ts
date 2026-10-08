import type { Kysely } from 'kysely';
import { describe, expect, it } from 'vitest';

import { buildApp } from '../../apps/api/src/app.js';
import { AcceptNoopCommandHandler } from '../../src/application/handlers/accept-noop-command-handler.js';
import { ExecutePaperEntryCommandHandler } from '../../src/application/handlers/execute-paper-entry-command-handler.js';
import { ObservePaperFinancialPositionCommandHandler } from '../../src/application/handlers/observe-paper-financial-position-command-handler.js';
import { ObservePaperPositionCommandHandler } from '../../src/application/handlers/observe-paper-position-command-handler.js';
import { RecordOpportunityCommandHandler } from '../../src/application/handlers/record-opportunity-command-handler.js';
import { RecordPaperPositionCommandHandler } from '../../src/application/handlers/record-paper-position-command-handler.js';
import type { Database } from '../../src/infrastructure/database/client.js';
import { CommandInboxRepository } from '../../src/infrastructure/database/repositories/command-inbox-repository.js';
import { OpportunityRepository } from '../../src/infrastructure/database/repositories/opportunity-repository.js';
import { OutboxRepository } from '../../src/infrastructure/database/repositories/outbox-repository.js';
import { PaperFinancialLifecycleRepository } from '../../src/infrastructure/database/repositories/paper-financial-lifecycle-repository.js';
import { PaperPositionLifecycleRepository } from '../../src/infrastructure/database/repositories/paper-position-lifecycle-repository.js';
import { PaperEntryRepository } from '../../src/infrastructure/database/repositories/paper-entry-repository.js';
import {
  createServiceToken,
  createTestIdentity,
} from '../support/test-environment.js';

describe('command authentication before request validation', () => {
  it('returns 401 for a missing opportunity assertion before validating the body', async () => {
    const { app } = createApp();

    const response = await app.inject({
      method: 'POST',
      url: '/v1/commands/opportunities',
      headers: { 'idempotency-key': 'missing-auth' },
      payload: { malformed: true },
    });

    expect(response.statusCode).toBe(401);
    expect(response.json()).toMatchObject({
      error: { code: 'AUTH_REQUIRED', retryable: false },
    });
    await app.close();
  });

  it('returns 401 for an invalid opportunity assertion before validating the body', async () => {
    const { app } = createApp();

    const response = await app.inject({
      method: 'POST',
      url: '/v1/commands/opportunities',
      headers: {
        authorization: 'Bearer invalid-token',
        'idempotency-key': 'invalid-auth',
      },
      payload: { malformed: true },
    });

    expect(response.statusCode).toBe(401);
    expect(response.json()).toMatchObject({
      error: { code: 'AUTH_INVALID', retryable: false },
    });
    await app.close();
  });

  it('returns 403 for a valid assertion without the opportunity-create scope', async () => {
    const { app, identity } = createApp();
    const token = await createServiceToken(identity, { scopes: ['commands:noop'] });

    const response = await app.inject({
      method: 'POST',
      url: '/v1/commands/opportunities',
      headers: {
        authorization: `Bearer ${token}`,
        'idempotency-key': 'wrong-scope',
      },
      payload: { malformed: true },
    });

    expect(response.statusCode).toBe(403);
    expect(response.json()).toMatchObject({
      error: { code: 'AUTH_SCOPE_DENIED', retryable: false },
    });
    await app.close();
  });

  it('returns 400 for a malformed opportunity after successful authentication', async () => {
    const { app, identity } = createApp();
    const token = await createServiceToken(identity, {
      scopes: ['commands:opportunities:create'],
    });

    const response = await app.inject({
      method: 'POST',
      url: '/v1/commands/opportunities',
      headers: {
        authorization: `Bearer ${token}`,
        'idempotency-key': 'malformed-body',
      },
      payload: { malformed: true },
    });

    expect(response.statusCode).toBe(400);
    expect(response.json()).toMatchObject({
      error: { code: 'VALIDATION_FAILED', retryable: false },
    });
    await app.close();
  });

  it('preserves the same authentication-first behavior for the no-op command', async () => {
    const { app } = createApp();

    const response = await app.inject({
      method: 'POST',
      url: '/v1/commands/noop',
      headers: { 'idempotency-key': 'noop-missing-auth' },
      payload: { unexpected: true },
    });

    expect(response.statusCode).toBe(401);
    expect(response.json()).toMatchObject({
      error: { code: 'AUTH_REQUIRED', retryable: false },
    });
    await app.close();
  });

  it.each([
    '/v1/commands/paper-entries',
    '/v1/commands/paper-financial-positions/observations',
    '/v1/commands/paper-positions',
    '/v1/commands/paper-positions/observations',
  ])('returns 401 for missing lifecycle authentication before body validation at %s', async (url) => {
    const { app } = createApp();

    const response = await app.inject({
      method: 'POST',
      url,
      headers: { 'idempotency-key': 'paper-missing-auth' },
      payload: { malformed: true },
    });

    expect(response.statusCode).toBe(401);
    expect(response.json()).toMatchObject({
      error: { code: 'AUTH_REQUIRED', retryable: false },
    });
    await app.close();
  });

  it.each([
    '/v1/commands/paper-entries',
    '/v1/commands/paper-financial-positions/observations',
    '/v1/commands/paper-positions',
    '/v1/commands/paper-positions/observations',
  ])('returns 403 for lifecycle authentication with the wrong scope at %s', async (url) => {
    const { app, identity } = createApp();
    const token = await createServiceToken(identity, { scopes: ['commands:noop'] });

    const response = await app.inject({
      method: 'POST',
      url,
      headers: {
        authorization: `Bearer ${token}`,
        'idempotency-key': 'paper-wrong-scope',
      },
      payload: { malformed: true },
    });

    expect(response.statusCode).toBe(403);
    expect(response.json()).toMatchObject({
      error: { code: 'AUTH_SCOPE_DENIED', retryable: false },
    });
    await app.close();
  });

  it.each([
    ['/v1/commands/paper-entries', 'commands:paper-entries:create'],
    ['/v1/commands/paper-financial-positions/observations', 'commands:paper-financial-positions:observe'],
    ['/v1/commands/paper-positions', 'commands:paper-positions:create'],
    ['/v1/commands/paper-positions/observations', 'commands:paper-positions:observe'],
  ])('returns 400 for a malformed lifecycle command after authentication at %s', async (url, scope) => {
    const { app, identity } = createApp();
    const token = await createServiceToken(identity, { scopes: [scope] });

    const response = await app.inject({
      method: 'POST',
      url,
      headers: {
        authorization: `Bearer ${token}`,
        'idempotency-key': 'paper-malformed',
      },
      payload: { malformed: true },
    });

    expect(response.statusCode).toBe(400);
    expect(response.json()).toMatchObject({
      error: { code: 'VALIDATION_FAILED', retryable: false },
    });
    await app.close();
  });
});

function createApp(): {
  readonly app: ReturnType<typeof buildApp>;
  readonly identity: ReturnType<typeof createTestIdentity>;
} {
  const identity = createTestIdentity();
  const database = {} as Kysely<Database>;
  const commandInbox = new CommandInboxRepository();
  const outbox = new OutboxRepository();
  const paperPositions = new PaperPositionLifecycleRepository();
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
      paperEntryHandler: new ExecutePaperEntryCommandHandler(
        database,
        commandInbox,
        new PaperEntryRepository(),
        outbox,
        {
          enabled: true,
          openingBalanceNative: '5',
          entryNotionalNative: '0.1',
          intentMaxAgeSeconds: 300,
        },
      ),
      paperFinancialObservationHandler: new ObservePaperFinancialPositionCommandHandler(
        database,
        commandInbox,
        paperPositions,
        new PaperFinancialLifecycleRepository(),
        outbox,
        {
          enabled: true,
          observationMaxAgeSeconds: 120,
        },
      ),
      paperPositionRegistrationHandler: new RecordPaperPositionCommandHandler(
        database,
        commandInbox,
        paperPositions,
        outbox,
      ),
      paperPositionObservationHandler: new ObservePaperPositionCommandHandler(
        database,
        commandInbox,
        paperPositions,
        outbox,
      ),
    }),
  };
}
