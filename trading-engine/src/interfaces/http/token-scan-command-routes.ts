import type { StartTokenScanCommandHandler } from '../../application/handlers/start-token-scan-command-handler.js';
import {
  TokenScanCommandResponseSchema,
  TokenScanCommandSchema,
  type TokenScanCommand,
} from '../../contracts/http/token-scan-command.schema.js';
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

export interface TokenScanCommandRouteDependencies {
  readonly handler: StartTokenScanCommandHandler;
  readonly requireServiceAuth: RequireServiceAuth;
}

export function registerTokenScanCommandRoutes(
  app: EngineFastifyInstance,
  dependencies: TokenScanCommandRouteDependencies,
): void {
  const serviceAuth = createPreValidationServiceAuth(
    dependencies.requireServiceAuth,
    'commands:token-scans:create',
  );

  app.post<{
    Body: TokenScanCommand;
    Headers: { readonly 'idempotency-key': string };
  }>(
    '/v1/commands/token-scans',
    {
      preValidation: serviceAuth.preValidation,
      schema: {
        body: TokenScanCommandSchema,
        headers: IdempotencyHeadersSchema,
        response: { 202: TokenScanCommandResponseSchema },
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
        body: request.body,
      });

      reply.code(202);
      return result;
    },
  );
}
