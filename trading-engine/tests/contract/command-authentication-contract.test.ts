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
});

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
    }),
  };
}
