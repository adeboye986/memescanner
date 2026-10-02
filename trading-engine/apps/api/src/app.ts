import { randomBytes, randomUUID, type KeyObject } from 'node:crypto';

import Fastify, {
  type FastifyRequest,
} from 'fastify';
import { jwtVerify } from 'jose';
import pino, { type DestinationStream, type Logger } from 'pino';

import type { AcceptNoopCommandHandler } from '../../../src/application/handlers/accept-noop-command-handler.js';
import type { RecordOpportunityCommandHandler } from '../../../src/application/handlers/record-opportunity-command-handler.js';
import type { EngineConfig } from '../../../src/config/env.js';
import type { Database } from '../../../src/infrastructure/database/client.js';
import { ApplicationError } from '../../../src/shared/errors/application-error.js';
import {
  registerHealthRoutes,
  type DatabasePing,
  type EngineFastifyInstance,
  type RequireServiceAuth,
  type ServiceClaims,
} from '../../../src/interfaces/http/health-routes.js';
import { registerNoopCommandRoutes } from '../../../src/interfaces/http/noop-command-routes.js';
import { registerOpportunityCommandRoutes } from '../../../src/interfaces/http/opportunity-command-routes.js';
import type { Kysely } from 'kysely';

const correlationIdPattern = /^[A-Za-z0-9._:-]{1,128}$/;
const traceparentPattern = /^00-[0-9a-f]{32}-[0-9a-f]{16}-0[01]$/;

export interface AppDependencies {
  readonly config: EngineConfig;
  readonly database: Kysely<Database>;
  readonly databasePing?: DatabasePing;
  readonly noopHandler: AcceptNoopCommandHandler;
  readonly opportunityHandler: RecordOpportunityCommandHandler;
  readonly logger?: Logger;
}

export function createLogger(
  config: Pick<EngineConfig, 'logLevel' | 'serviceName' | 'serviceVersion'>,
  destination?: DestinationStream,
): Logger {
  return pino(
    {
      level: config.logLevel,
      base: {
        service: config.serviceName,
        version: config.serviceVersion,
      },
      redact: {
        paths: [
          'req.headers.authorization',
          'req.headers.cookie',
          'headers.authorization',
          'authorization',
          'token',
          'password',
          'secret',
          '*.password',
          '*.secret',
          '*.privateKey',
          '*.seedPhrase',
        ],
        censor: '[REDACTED]',
      },
    },
    destination,
  );
}

export function buildApp(
  dependencies: AppDependencies,
): EngineFastifyInstance {
  const logger = dependencies.logger ?? createLogger(dependencies.config);
  const requireServiceAuth = createServiceAuthenticator(
    dependencies.config,
    dependencies.config.serviceAuthPublicKey,
  );
  const app = Fastify({
    loggerInstance: logger,
    genReqId: (request) => {
      const incoming = request.headers['x-correlation-id'];

      return typeof incoming === 'string' && correlationIdPattern.test(incoming)
        ? incoming
        : randomUUID();
    },
    requestIdHeader: false,
    ajv: {
      customOptions: {
        allErrors: false,
        coerceTypes: false,
        removeAdditional: false,
      },
    },
  });

  app.addHook('onRequest', (request, reply, done): void => {
    const incomingTraceparent = request.headers['traceparent'];
    const traceparent =
      typeof incomingTraceparent === 'string' && traceparentPattern.test(incomingTraceparent)
        ? incomingTraceparent
        : createTraceparent();

    request.headers['traceparent'] = traceparent;
    reply.header('x-correlation-id', request.id);
    reply.header('traceparent', traceparent);
    done();
  });

  app.setErrorHandler((error, request, reply): void => {
    if (error instanceof ApplicationError) {
      reply.code(error.statusCode).send({
        error: {
          code: error.code,
          message: error.message,
          retryable: error.retryable,
        },
        correlationId: request.id,
      });
      return;
    }

    if (
      typeof error === 'object'
      && error !== null
      && 'validation' in error
      && error.validation !== undefined
    ) {
      reply.code(400).send({
        error: {
          code: 'VALIDATION_FAILED',
          message: 'The request did not match the API contract',
          retryable: false,
        },
        correlationId: request.id,
      });
      return;
    }

    request.log.error(
      {
        err: error,
        correlationId: request.id,
        traceparent: request.headers['traceparent'],
      },
      'unhandled request error',
    );
    reply.code(500).send({
      error: {
        code: 'INTERNAL_ERROR',
        message: 'The request could not be completed',
        retryable: false,
      },
      correlationId: request.id,
    });
  });

  registerHealthRoutes(app, {
    config: dependencies.config,
    database: dependencies.database,
    ...(dependencies.databasePing === undefined
      ? {}
      : { databasePing: dependencies.databasePing }),
    requireServiceAuth,
  });
  registerNoopCommandRoutes(app, {
    handler: dependencies.noopHandler,
    requireServiceAuth,
  });
  registerOpportunityCommandRoutes(app, {
    handler: dependencies.opportunityHandler,
    requireServiceAuth,
  });

  return app;
}

function createServiceAuthenticator(
  config: EngineConfig,
  publicKey: KeyObject,
): RequireServiceAuth {
  return async (
    request: FastifyRequest,
    requiredScope: string,
  ): Promise<ServiceClaims> => {
    const authorization = request.headers.authorization;

    if (!authorization?.startsWith('Bearer ')) {
      throw new ApplicationError(
        'AUTH_REQUIRED',
        'A service bearer assertion is required',
        401,
        false,
      );
    }

    const token = authorization.slice('Bearer '.length).trim();

    if (token === '') {
      throw new ApplicationError(
        'AUTH_REQUIRED',
        'A service bearer assertion is required',
        401,
        false,
      );
    }

    try {
      const verified = await jwtVerify(token, publicKey, {
        algorithms: ['EdDSA'],
        issuer: config.serviceAuthIssuer,
        audience: config.serviceAuthAudience,
        clockTolerance: config.serviceAuthClockToleranceSeconds,
        maxTokenAge: config.serviceAuthMaxTokenAgeSeconds,
        requiredClaims: ['iat', 'exp', 'jti', 'sub'],
      });
      const subject = verified.payload.sub;
      const jti = verified.payload.jti;
      const scopeClaim = verified.payload['scope'];
      const scopes = new Set(
        typeof scopeClaim === 'string'
          ? scopeClaim.split(' ').filter((scope) => scope !== '')
          : [],
      );

      if (subject === undefined || jti === undefined) {
        throw new Error('Required service claims are missing');
      }

      if (!scopes.has(requiredScope)) {
        throw new ApplicationError(
          'AUTH_SCOPE_DENIED',
          'The service assertion does not grant the required scope',
          403,
          false,
        );
      }

      return {
        subject,
        jti,
        scopes,
      };
    } catch (error) {
      if (error instanceof ApplicationError) {
        throw error;
      }

      throw new ApplicationError(
        'AUTH_INVALID',
        'The service bearer assertion is invalid or expired',
        401,
        false,
      );
    }
  };
}

function createTraceparent(): string {
  const traceId = randomBytes(16).toString('hex');
  const spanId = randomBytes(8).toString('hex');

  return `00-${traceId}-${spanId}-01`;
}
