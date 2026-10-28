import type { Kysely } from 'kysely';

import {
  TokenScanCandidatesParamsSchema,
  TokenScanCandidatesQuerySchema,
  TokenScanCandidatesResponseSchema,
  type TokenScanCandidatesParams,
  type TokenScanCandidatesQuery,
  type TokenScanCandidatesResponse,
} from '../../contracts/http/token-scan-candidates.schema.js';
import type { Database, JsonValue } from '../../infrastructure/database/client.js';
import type {
  TokenDiscoveryRecord,
  TokenScanRepository,
} from '../../infrastructure/database/repositories/token-scan-repository.js';
import { ApplicationError } from '../../shared/errors/application-error.js';
import {
  createPreValidationServiceAuth,
  type EngineFastifyInstance,
  type RequireServiceAuth,
} from './health-routes.js';

export interface TokenScanCandidateRouteDependencies {
  readonly database: Kysely<Database>;
  readonly repository: TokenScanRepository;
  readonly requireServiceAuth: RequireServiceAuth;
}

export function registerTokenScanCandidateRoutes(
  app: EngineFastifyInstance,
  dependencies: TokenScanCandidateRouteDependencies,
): void {
  const serviceAuth = createPreValidationServiceAuth(
    dependencies.requireServiceAuth,
    'token-scans:candidates:read',
  );

  app.get<{
    Params: TokenScanCandidatesParams;
    Querystring: TokenScanCandidatesQuery;
  }>(
    '/v1/token-scans/:scanExecutionId/candidates',
    {
      preValidation: serviceAuth.preValidation,
      schema: {
        params: TokenScanCandidatesParamsSchema,
        querystring: TokenScanCandidatesQuerySchema,
        response: { 200: TokenScanCandidatesResponseSchema },
      },
    },
    async (request): Promise<TokenScanCandidatesResponse> => {
      serviceAuth.claimsFor(request);
      const page = Number(request.query.page);
      const perPage = Number(request.query.per_page);
      const result = await dependencies.repository.paginateCandidateDiagnostics(
        dependencies.database,
        request.params.scanExecutionId,
        request.query.control_plane_user_id,
        page,
        perPage,
      );

      if (result === null) {
        throw new ApplicationError(
          'TOKEN_SCAN_NOT_FOUND',
          'The token scan was not found',
          404,
          false,
        );
      }

      return {
        schema_version: 1,
        scan_execution_id: result.execution.id,
        control_plane_user_id: result.execution.control_plane_user_id,
        candidates: result.candidates.map(presentCandidate),
        pagination: {
          page,
          per_page: perPage,
          total: result.total,
          last_page: Math.max(1, Math.ceil(result.total / perPage)),
        },
      };
    },
  );
}

function presentCandidate(candidate: TokenDiscoveryRecord): TokenScanCandidatesResponse['candidates'][number] {
  return {
    address: candidate.asset_address,
    symbol: candidate.symbol,
    name: candidate.name,
    provider: candidate.provider,
    state: candidate.state,
    reason_code: candidate.reason_code,
    market: marketSummary(candidate.market_snapshot),
    security_status: securityStatus(candidate.security_snapshot),
    opportunity_id: candidate.opportunity_id,
    evaluated_at: candidate.updated_at.toISOString(),
  };
}

function marketSummary(value: JsonValue | null): TokenScanCandidatesResponse['candidates'][number]['market'] {
  const snapshot = jsonObject(value);

  if (snapshot === null) {
    return null;
  }

  const market = {
    market_cap_usd: metric(snapshot, ['marketCap']),
    price_usd: metric(snapshot, ['price', 'priceUsd']),
    liquidity_usd: metric(snapshot, ['liquidity', 'liquidityUsd']),
    volume_5m_usd: metric(snapshot, ['volume5m']),
    holders: metric(snapshot, ['holders']),
  };

  return Object.values(market).every((metricValue) => metricValue === null)
    ? null
    : market;
}

function securityStatus(value: JsonValue | null): 'not_reported' | 'unavailable' | 'passed' | 'failed' {
  const snapshot = jsonObject(value);

  if (snapshot === null) {
    return 'not_reported';
  }

  if (snapshot['available'] === false) {
    return 'unavailable';
  }

  if (snapshot['passed'] === true) {
    return 'passed';
  }

  if (snapshot['passed'] === false) {
    return 'failed';
  }

  return 'not_reported';
}

function jsonObject(value: JsonValue | null): Readonly<Record<string, JsonValue>> | null {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) {
    return null;
  }

  return value as Readonly<Record<string, JsonValue>>;
}

function metric(snapshot: Readonly<Record<string, JsonValue>>, keys: readonly string[]): number | null {
  for (const key of keys) {
    const value = snapshot[key];

    if (typeof value === 'number' && Number.isFinite(value) && value >= 0) {
      return value;
    }
  }

  return null;
}
