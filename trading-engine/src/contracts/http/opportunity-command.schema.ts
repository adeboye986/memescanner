import { Type, type Static } from '@sinclair/typebox';

import {
  NonNegativeDecimalStringSchema,
  SignedDecimalStringSchema,
} from '../../shared/amount/canonical-decimal.js';

export const SOLANA_MAINNET_ID = 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp' as const;
export const ETHEREUM_MAINNET_ID = 'eip155:1' as const;

const MarketProviderSchema = Type.Union([
  Type.Literal('birdeye'),
  Type.Literal('dexscreener'),
]);

const UsdMeasurementSchema = Type.Object(
  {
    value: NonNegativeDecimalStringSchema,
    provider: MarketProviderSchema,
  },
  { additionalProperties: false },
);

const VolumeMeasurementSchema = Type.Object(
  {
    value: NonNegativeDecimalStringSchema,
    provider: MarketProviderSchema,
    window: Type.Union([Type.Literal('1m'), Type.Literal('5m')]),
  },
  { additionalProperties: false },
);

const PairSchema = Type.Object(
  {
    address: Type.String({ minLength: 1, maxLength: 128 }),
    dex: Type.Optional(Type.String({ minLength: 1, maxLength: 128 })),
    provider: Type.Literal('dexscreener'),
  },
  { additionalProperties: false },
);

const HolderConcentrationSchema = Type.Object(
  {
    largest_holder_percent: Type.Optional(NonNegativeDecimalStringSchema),
    top_5_percent: Type.Optional(NonNegativeDecimalStringSchema),
    top_10_percent: Type.Optional(NonNegativeDecimalStringSchema),
    risk_level: Type.Optional(Type.String({ minLength: 1, maxLength: 64 })),
  },
  { additionalProperties: false },
);

const MarketValidationSchema = Type.Object(
  {
    provider: Type.Literal('dexscreener'),
    requested_token_is_base: Type.Boolean(),
    pair_available: Type.Boolean(),
  },
  { additionalProperties: false },
);

const SecuritySchema = Type.Object(
  {
    status: Type.Union([
      Type.Literal('passed'),
      Type.Literal('failed'),
      Type.Literal('unavailable'),
    ]),
    provider: Type.Optional(Type.Union([
      Type.Literal('goplus'),
      Type.Literal('solana_rpc_holder_analysis'),
    ])),
    passed: Type.Optional(Type.Boolean()),
    score: Type.Optional(Type.Integer({ minimum: 0, maximum: 100 })),
    risks: Type.Optional(Type.Array(Type.String({ maxLength: 512 }), { maxItems: 100 })),
    coverage: Type.Optional(Type.String({ minLength: 1, maxLength: 512 })),
    holder_concentration: Type.Optional(HolderConcentrationSchema),
    market_validation: Type.Optional(MarketValidationSchema),
    unavailable_checks: Type.Optional(
      Type.Array(Type.String({ minLength: 1, maxLength: 128 }), { maxItems: 100 }),
    ),
  },
  { additionalProperties: false },
);

export const OpportunityCommandSchema = Type.Object(
  {
    schema_version: Type.Literal(1),
    source: Type.Object(
      {
        system: Type.Literal('meme-scanner-laravel'),
        opportunity_id: Type.String({ minLength: 1, maxLength: 64 }),
        discovery_key: Type.String({ pattern: '^[0-9a-f]{64}$' }),
        scanner: Type.Union([Type.Literal('new-token'), Type.Literal('momentum')]),
      },
      { additionalProperties: false },
    ),
    subject: Type.Object(
      {
        control_plane_user_id: Type.String({ minLength: 1, maxLength: 64 }),
      },
      { additionalProperties: false },
    ),
    network: Type.Object(
      {
        id: Type.Union([
          Type.Literal(SOLANA_MAINNET_ID),
          Type.Literal(ETHEREUM_MAINNET_ID),
        ]),
      },
      { additionalProperties: false },
    ),
    asset: Type.Object(
      {
        address: Type.String({ minLength: 1, maxLength: 128 }),
        symbol: Type.Optional(Type.String({ minLength: 1, maxLength: 64 })),
        name: Type.Optional(Type.String({ minLength: 1, maxLength: 255 })),
      },
      { additionalProperties: false },
    ),
    market_snapshot: Type.Object(
      {
        price_usd: Type.Optional(UsdMeasurementSchema),
        market_cap_usd: Type.Optional(UsdMeasurementSchema),
        liquidity_usd: Type.Optional(UsdMeasurementSchema),
        volume_usd: Type.Optional(VolumeMeasurementSchema),
        pair: Type.Optional(PairSchema),
      },
      { additionalProperties: false },
    ),
    qualification: Type.Object(
      {
        qualified_at: Type.String({ format: 'date-time' }),
        discovery_market_cap_usd: Type.Optional(NonNegativeDecimalStringSchema),
        move_since_discovery_percent: Type.Optional(SignedDecimalStringSchema),
        classification: Type.Optional(Type.String({ minLength: 1, maxLength: 64 })),
      },
      { additionalProperties: false },
    ),
    security: Type.Optional(SecuritySchema),
  },
  { additionalProperties: false },
);

export type OpportunityCommand = Static<typeof OpportunityCommandSchema>;

export const OpportunityCommandResponseSchema = Type.Object(
  {
    operationId: Type.String({ minLength: 26, maxLength: 26 }),
    opportunityId: Type.String({ minLength: 26, maxLength: 26 }),
    eventId: Type.String({ minLength: 26, maxLength: 26 }),
    status: Type.Literal('accepted'),
    duplicate: Type.Boolean(),
  },
  { additionalProperties: false },
);

export type OpportunityCommandResponse = Static<typeof OpportunityCommandResponseSchema>;
