import type { ExecutePaperEntryCommandHandler } from '../../application/handlers/execute-paper-entry-command-handler.js';
import {
  PaperEntryCommandResponseSchema,
  PaperEntryCommandSchema,
  type PaperEntryCommandBody,
} from '../../contracts/http/paper-entry-command.schema.js';
import {
  createPreValidationServiceAuth,
  type EngineFastifyInstance,
  type RequireServiceAuth,
} from './health-routes.js';

const IdempotencyHeadersSchema = {
  type: 'object',
  required: ['authorization', 'idempotency-key'],
  properties: {
    authorization: { type: 'string', minLength: 8 },
    'idempotency-key': {
      type: 'string',
      minLength: 1,
      maxLength: 128,
      pattern: '^[A-Za-z0-9._:-]+$',
    },
  },
} as const;

export function registerPaperEntryCommandRoutes(
  app: EngineFastifyInstance,
  dependencies: {
    readonly handler: ExecutePaperEntryCommandHandler;
    readonly requireServiceAuth: RequireServiceAuth;
  },
): void {
  const authentication = createPreValidationServiceAuth(
    dependencies.requireServiceAuth,
    'commands:paper-entries:create',
  );

  app.post<{
    Body: PaperEntryCommandBody;
    Headers: { readonly 'idempotency-key': string };
  }>('/v1/commands/paper-entries', {
    preValidation: authentication.preValidation,
    schema: {
      body: PaperEntryCommandSchema,
      headers: IdempotencyHeadersSchema,
      response: {
        202: PaperEntryCommandResponseSchema,
      },
    },
  }, async (request, reply) => {
    const claims = authentication.claimsFor(request);
    const response = await dependencies.handler.execute({
      body: request.body,
      idempotencyKey: request.headers['idempotency-key'],
      authJti: claims.jti,
      subject: claims.subject,
      correlationId: request.id,
      traceparent: request.headers['traceparent'] as string,
    });

    reply.code(202);
    return response;
  });
}
