import type { ObservePaperPositionCommandHandler } from '../../application/handlers/observe-paper-position-command-handler.js';
import type { RecordPaperPositionCommandHandler } from '../../application/handlers/record-paper-position-command-handler.js';
import {
  PaperPositionObservationSchema,
  PaperPositionRegistrationSchema,
  type PaperPositionObservation,
  type PaperPositionRegistration,
} from '../../contracts/http/paper-position-command.schema.js';
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

export function registerPaperPositionCommandRoutes(
  app: EngineFastifyInstance,
  dependencies: {
    readonly registrationHandler: RecordPaperPositionCommandHandler;
    readonly observationHandler: ObservePaperPositionCommandHandler;
    readonly requireServiceAuth: RequireServiceAuth;
  },
): void {
  const registrationAuth = createPreValidationServiceAuth(
    dependencies.requireServiceAuth,
    'commands:paper-positions:create',
  );
  const observationAuth = createPreValidationServiceAuth(
    dependencies.requireServiceAuth,
    'commands:paper-positions:observe',
  );

  app.post<{
    Body: PaperPositionRegistration;
    Headers: { readonly 'idempotency-key': string };
  }>('/v1/commands/paper-positions', {
    preValidation: registrationAuth.preValidation,
    schema: {
      body: PaperPositionRegistrationSchema,
      headers: IdempotencyHeadersSchema,
    },
  }, async (request, reply) => {
    const claims = registrationAuth.claimsFor(request);
    const response = await dependencies.registrationHandler.execute({
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

  app.post<{
    Body: PaperPositionObservation;
    Headers: { readonly 'idempotency-key': string };
  }>('/v1/commands/paper-positions/observations', {
    preValidation: observationAuth.preValidation,
    schema: {
      body: PaperPositionObservationSchema,
      headers: IdempotencyHeadersSchema,
    },
  }, async (request, reply) => {
    const claims = observationAuth.claimsFor(request);
    const response = await dependencies.observationHandler.execute({
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
