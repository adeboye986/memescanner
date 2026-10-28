import { Type, type Static } from '@sinclair/typebox';

const CandidateStateSchema = Type.Union([
  Type.Literal('discovered'),
  Type.Literal('skipped'),
  Type.Literal('qualified'),
  Type.Literal('qualified_downstream_failed'),
  Type.Literal('opportunity_created'),
  Type.Literal('opportunity_existing'),
  Type.Literal('failed'),
]);

const NullableMetricSchema = Type.Union([
  Type.Number({ minimum: 0 }),
  Type.Null(),
]);

export const TokenScanCandidatesParamsSchema = Type.Object(
  {
    scanExecutionId: Type.String({
      minLength: 26,
      maxLength: 26,
      pattern: '^[0-9A-HJKMNP-TV-Z]{26}$',
    }),
  },
  { additionalProperties: false },
);

export const TokenScanCandidatesQuerySchema = Type.Object(
  {
    control_plane_user_id: Type.String({ pattern: '^[1-9][0-9]{0,63}$' }),
    page: Type.String({ pattern: '^(?:[1-9]|[1-9][0-9]{1,3}|10000)$' }),
    per_page: Type.String({ pattern: '^(?:[1-9]|[1-4][0-9]|50)$' }),
  },
  { additionalProperties: false },
);

export const TokenScanCandidatesResponseSchema = Type.Object(
  {
    schema_version: Type.Literal(1),
    scan_execution_id: Type.String({ minLength: 26, maxLength: 26 }),
    control_plane_user_id: Type.String({ pattern: '^[1-9][0-9]{0,63}$' }),
    candidates: Type.Array(Type.Object(
      {
        address: Type.String({ minLength: 1, maxLength: 128 }),
        symbol: Type.Union([Type.String({ minLength: 1, maxLength: 64 }), Type.Null()]),
        name: Type.Union([Type.String({ minLength: 1, maxLength: 255 }), Type.Null()]),
        provider: Type.Union([
          Type.Literal('birdeye'),
          Type.Literal('dexscreener'),
          Type.Literal('geckoterminal'),
        ]),
        state: CandidateStateSchema,
        reason_code: Type.Union([
          Type.String({ pattern: '^[A-Z][A-Z0-9_]{0,127}$' }),
          Type.Null(),
        ]),
        market: Type.Union([
          Type.Object(
            {
              market_cap_usd: NullableMetricSchema,
              price_usd: NullableMetricSchema,
              liquidity_usd: NullableMetricSchema,
              volume_5m_usd: NullableMetricSchema,
              holders: NullableMetricSchema,
            },
            { additionalProperties: false },
          ),
          Type.Null(),
        ]),
        security_status: Type.Union([
          Type.Literal('not_reported'),
          Type.Literal('unavailable'),
          Type.Literal('passed'),
          Type.Literal('failed'),
        ]),
        opportunity_id: Type.Union([
          Type.String({ minLength: 26, maxLength: 26 }),
          Type.Null(),
        ]),
        evaluated_at: Type.String({ format: 'date-time' }),
      },
      { additionalProperties: false },
    ), { maxItems: 50 }),
    pagination: Type.Object(
      {
        page: Type.Integer({ minimum: 1, maximum: 10000 }),
        per_page: Type.Integer({ minimum: 1, maximum: 50 }),
        total: Type.Integer({ minimum: 0 }),
        last_page: Type.Integer({ minimum: 1 }),
      },
      { additionalProperties: false },
    ),
  },
  { additionalProperties: false },
);

export type TokenScanCandidatesParams = Static<typeof TokenScanCandidatesParamsSchema>;
export type TokenScanCandidatesQuery = Static<typeof TokenScanCandidatesQuerySchema>;
export type TokenScanCandidatesResponse = Static<typeof TokenScanCandidatesResponseSchema>;
