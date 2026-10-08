import type { ObservePaperFinancialPositionCommandHandler } from '../../application/handlers/observe-paper-financial-position-command-handler.js';
import {
  PaperFinancialObservationResponseSchema,
  PaperFinancialObservationSchema,
  type PaperFinancialObservation,
} from '../../contracts/http/paper-financial-observation-command.schema.js';
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

export function registerPaperFinancialObservationCommandRoutes(
  app: EngineFastifyInstance,
  dependencies: {
    readonly handler: ObservePaperFinancialPositionCommandHandler;
    readonly requireServiceAuth: RequireServiceAuth;
  },
): void {
  const authentication = createPreValidationServiceAuth(
    dependencies.requireServiceAuth,
    'commands:paper-financial-positions:observe',
  );

  app.post<{
    Body: PaperFinancialObservation;
    Headers: { readonly 'idempotency-key': string };
  }>('/v1/commands/paper-financial-positions/observations', {
    preValidation: authentication.preValidation,
    schema: {
      body: PaperFinancialObservationSchema,
      headers: IdempotencyHeadersSchema,
      response: {
        202: PaperFinancialObservationResponseSchema,
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
