import type { IncomingMessage, Server, ServerResponse } from 'node:http';

import type { FastifyInstance, FastifyRequest } from 'fastify';
import type { Logger } from 'pino';
import type { Redis } from 'ioredis';
import type { Kysely } from 'kysely';

import type { EngineConfig } from '../../config/env.js';
import {
  HealthStatusSchema,
  VersionSchema,
  type HealthStatus,
} from '../../contracts/http/health.schema.js';
import { pingDatabase, type Database } from '../../infrastructure/database/client.js';
import { pingRedis } from '../../infrastructure/queue/connection.js';

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

export interface HealthRouteDependencies {
  readonly config: EngineConfig;
  readonly database: Kysely<Database>;
  readonly redis: Redis;
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

      const [postgres, redis] = await Promise.allSettled([
        pingDatabase(dependencies.database),
        pingRedis(dependencies.redis),
      ]);
      const ready = postgres.status === 'fulfilled' && redis.status === 'fulfilled';
      const status: HealthStatus = {
        status: ready ? 'ok' : 'unavailable',
        service: dependencies.config.serviceName,
        version: dependencies.config.serviceVersion,
        timestamp: new Date().toISOString(),
        dependencies: {
          postgres: postgres.status === 'fulfilled' ? 'up' : 'down',
          redis: redis.status === 'fulfilled' ? 'up' : 'down',
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
