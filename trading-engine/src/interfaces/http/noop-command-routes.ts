import type { AcceptNoopCommandHandler } from '../../application/handlers/accept-noop-command-handler.js';
import {
  NoopCommandResponseSchema,
  NoopCommandSchema,
  type NoopCommand,
} from '../../contracts/http/noop-command.schema.js';
import { ApplicationError } from '../../shared/errors/application-error.js';
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

export interface NoopRouteDependencies {
  readonly handler: AcceptNoopCommandHandler;
  readonly requireServiceAuth: RequireServiceAuth;
}

export function registerNoopCommandRoutes(
  app: EngineFastifyInstance,
  dependencies: NoopRouteDependencies,
): void {
  const serviceAuth = createPreValidationServiceAuth(
    dependencies.requireServiceAuth,
    'commands:noop',
  );

  app.post<{ Body: NoopCommand; Headers: { readonly 'idempotency-key': string } }>(
    '/v1/commands/noop',
    {
      preValidation: serviceAuth.preValidation,
      schema: {
        body: NoopCommandSchema,
        headers: IdempotencyHeadersSchema,
        response: {
          202: NoopCommandResponseSchema,
        },
      },
    },
    async (request, reply) => {
      const claims = serviceAuth.claimsFor(request);
      const result = await dependencies.handler.execute({
        idempotencyKey: request.headers['idempotency-key'],
        authJti: claims.jti,
        subject: claims.subject,
        correlationId: request.id,
        traceparent: request.headers['traceparent'] as string,
        ...(request.body.message === undefined ? {} : { message: request.body.message }),
      });

      reply.code(202);
      return result;
    },
  );

  app.setNotFoundHandler(() => {
    throw new ApplicationError('NOT_FOUND', 'Route not found', 404, false);
  });
}
