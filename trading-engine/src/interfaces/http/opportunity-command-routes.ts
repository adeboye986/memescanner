import type { RecordOpportunityCommandHandler } from '../../application/handlers/record-opportunity-command-handler.js';
import {
  OpportunityCommandResponseSchema,
  OpportunityCommandSchema,
  type OpportunityCommand,
} from '../../contracts/http/opportunity-command.schema.js';
import type { EngineFastifyInstance, RequireServiceAuth } from './health-routes.js';

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

export interface OpportunityCommandRouteDependencies {
  readonly handler: RecordOpportunityCommandHandler;
  readonly requireServiceAuth: RequireServiceAuth;
}

export function registerOpportunityCommandRoutes(
  app: EngineFastifyInstance,
  dependencies: OpportunityCommandRouteDependencies,
): void {
  app.post<{
    Body: OpportunityCommand;
    Headers: { readonly 'idempotency-key': string };
  }>(
    '/v1/commands/opportunities',
    {
      schema: {
        body: OpportunityCommandSchema,
        headers: IdempotencyHeadersSchema,
        response: {
          202: OpportunityCommandResponseSchema,
        },
      },
    },
    async (request, reply) => {
      const claims = await dependencies.requireServiceAuth(
        request,
        'commands:opportunities:create',
      );
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
