import type { IncomingMessage, Server, ServerResponse } from 'node:http';

import type { FastifyInstance, FastifyRequest } from 'fastify';
import type { Logger } from 'pino';
import type { Kysely } from 'kysely';

import type { EngineConfig } from '../../config/env.js';
import {
  HealthStatusSchema,
  VersionSchema,
  type HealthStatus,
} from '../../contracts/http/health.schema.js';
import { pingDatabase, type Database } from '../../infrastructure/database/client.js';
import {
  formatDatabaseSchemaVersion,
  inspectDatabaseSchemaCompatibility,
  type DatabaseSchemaCompatibility,
} from '../../infrastructure/database/schema-compatibility.js';

export type EngineFastifyInstance = FastifyInstance<
  Server,
  IncomingMessage,
  ServerResponse,
  Logger
>;

export interface ServiceClaims {
  readonly subject: string;
  readonly jti: string;
  readonly scopes: ReadonlySet<string>;
}

export type RequireServiceAuth = (
  request: FastifyRequest,
  requiredScope: string,
) => Promise<ServiceClaims>;

export type DatabasePing = (
  database: Kysely<Database>,
) => Promise<void>;

export type DatabaseSchemaCompatibilityCheck = (
  database: Kysely<Database>,
) => Promise<DatabaseSchemaCompatibility>;

export interface PreValidationServiceAuth {
  readonly preValidation: (request: FastifyRequest) => Promise<void>;
  readonly claimsFor: (request: FastifyRequest) => ServiceClaims;
}

export function createPreValidationServiceAuth(
  requireServiceAuth: RequireServiceAuth,
  requiredScope: string,
): PreValidationServiceAuth {
  const claimsByRequest = new WeakMap<FastifyRequest, ServiceClaims>();

  return {
    preValidation: async (request): Promise<void> => {
      claimsByRequest.set(
        request,
        await requireServiceAuth(request, requiredScope),
      );
    },
    claimsFor: (request): ServiceClaims => {
      const claims = claimsByRequest.get(request);

      if (claims === undefined) {
        throw new Error('Service claims were not established before route handling');
      }

      return claims;
    },
  };
}

export interface HealthRouteDependencies {
  readonly config: EngineConfig;
  readonly database: Kysely<Database>;
  readonly databasePing?: DatabasePing;
  readonly databaseSchemaCompatibilityCheck?: DatabaseSchemaCompatibilityCheck;
  readonly requireServiceAuth: RequireServiceAuth;
}

export function registerHealthRoutes(
  app: EngineFastifyInstance,
  dependencies: HealthRouteDependencies,
): void {
  app.get(
    '/v1/health/live',
    {
      schema: {
        response: {
          200: HealthStatusSchema,
        },
      },
    },
    async (request): Promise<HealthStatus> => {
      await dependencies.requireServiceAuth(request, 'health:read');

      return {
        status: 'ok',
        service: dependencies.config.serviceName,
        version: dependencies.config.serviceVersion,
        timestamp: new Date().toISOString(),
      };
    },
  );

  app.get(
    '/v1/health/ready',
    {
      schema: {
        response: {
          200: HealthStatusSchema,
          503: HealthStatusSchema,
        },
      },
    },
    async (request, reply): Promise<HealthStatus> => {
      await dependencies.requireServiceAuth(request, 'health:read');

      const databasePing = dependencies.databasePing ?? pingDatabase;
      const databaseSchemaCompatibilityCheck =
        dependencies.databaseSchemaCompatibilityCheck
        ?? ((database): Promise<DatabaseSchemaCompatibility> =>
          inspectDatabaseSchemaCompatibility(database, dependencies.config));
      const [postgres, schema] = await Promise.allSettled([
        databasePing(dependencies.database),
        databaseSchemaCompatibilityCheck(dependencies.database),
      ]);
      const schemaCompatible = schema.status === 'fulfilled'
        && schema.value.compatible;
      const ready = postgres.status === 'fulfilled' && schemaCompatible;

      if (schema.status === 'fulfilled') {
        reply.header(
          'x-database-schema-version',
          formatDatabaseSchemaVersion(schema.value.detectedVersion),
        );
        reply.header(
          'x-database-schema-required-version',
          formatDatabaseSchemaVersion(schema.value.requiredVersion),
        );

        if (!schema.value.compatible) {
          request.log.warn(
            {
              errorCode: 'DATABASE_SCHEMA_INCOMPATIBLE',
              detectedSchemaVersion: schema.value.detectedVersion,
              requiredSchemaVersion: schema.value.requiredVersion,
              missingCapabilities: schema.value.missingCapabilities,
            },
            'Database schema is incompatible with enabled capabilities',
          );
        }
      } else if (postgres.status === 'fulfilled') {
        request.log.error(
          { errorCode: 'DATABASE_SCHEMA_CHECK_FAILED' },
          'Database schema compatibility check failed',
        );
      }

      const status: HealthStatus = {
        status: ready ? 'ok' : 'unavailable',
        service: dependencies.config.serviceName,
        version: dependencies.config.serviceVersion,
        timestamp: new Date().toISOString(),
        dependencies: {
          postgres: postgres.status === 'fulfilled' ? 'up' : 'down',
          redis: 'not_required',
        },
      };

      if (!ready) {
        reply.code(503);
      }

      return status;
    },
  );

  app.get(
    '/v1/version',
    {
      schema: {
        response: {
          200: VersionSchema,
        },
      },
    },
    async (request) => {
      await dependencies.requireServiceAuth(request, 'health:read');

      return {
        service: dependencies.config.serviceName,
        version: dependencies.config.serviceVersion,
        node: process.version,
      };
    },
  );
}
