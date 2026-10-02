import { Type, type Static } from '@sinclair/typebox';

import { NonNegativeDecimalStringSchema } from '../../shared/amount/canonical-decimal.js';
import { SOLANA_MAINNET_ID } from './opportunity-command.schema.js';

const EngineIdSchema = Type.String({ pattern: '^[0-9A-HJKMNP-TV-Z]{26}$' });
const PositiveIdSchema = Type.String({ pattern: '^[1-9][0-9]*$' });

export const PaperPositionRegistrationSchema = Type.Object({
  schema_version: Type.Literal(1),
  source: Type.Object({
    system: Type.Literal('meme-scanner-laravel'),
    paper_position_id: PositiveIdSchema,
    trade_opportunity_id: PositiveIdSchema,
    engine_opportunity_id: EngineIdSchema,
  }, { additionalProperties: false }),
  subject: Type.Object({ control_plane_user_id: PositiveIdSchema }, { additionalProperties: false }),
  network: Type.Object({ id: Type.Literal(SOLANA_MAINNET_ID) }, { additionalProperties: false }),
  asset: Type.Object({
    address: Type.String({ minLength: 32, maxLength: 44 }),
    symbol: Type.Optional(Type.String({ minLength: 1, maxLength: 32 })),
  }, { additionalProperties: false }),
  entry: Type.Object({
    initial_investment_native: NonNegativeDecimalStringSchema,
    market_cap_usd: NonNegativeDecimalStringSchema,
    price_usd: Type.Optional(NonNegativeDecimalStringSchema),
    liquidity_usd: Type.Optional(NonNegativeDecimalStringSchema),
    entered_at: Type.String({ format: 'date-time' }),
  }, { additionalProperties: false }),
  strategy: Type.Object({
    stop_loss_percent: NonNegativeDecimalStringSchema,
    protection_level_1_percent: NonNegativeDecimalStringSchema,
    protection_level_2_percent: NonNegativeDecimalStringSchema,
  }, { additionalProperties: false }),
}, { additionalProperties: false });

export const PaperPositionObservationSchema = Type.Object({
  schema_version: Type.Literal(1),
  position_id: EngineIdSchema,
  source: Type.Object({
    paper_position_id: PositiveIdSchema,
    observation_id: Type.String({ pattern: '^[A-Za-z0-9._:-]{1,128}$' }),
    sequence: Type.Integer({ minimum: 1 }),
  }, { additionalProperties: false }),
  subject: Type.Object({ control_plane_user_id: PositiveIdSchema }, { additionalProperties: false }),
  network: Type.Object({ id: Type.Literal(SOLANA_MAINNET_ID) }, { additionalProperties: false }),
  asset: Type.Object({ address: Type.String({ minLength: 32, maxLength: 44 }) }, { additionalProperties: false }),
  market: Type.Object({
    market_cap_usd: NonNegativeDecimalStringSchema,
    price_usd: Type.Optional(NonNegativeDecimalStringSchema),
    liquidity_usd: Type.Optional(NonNegativeDecimalStringSchema),
    observed_at: Type.String({ format: 'date-time' }),
    fetched_at: Type.String({ format: 'date-time' }),
    provider: Type.String({ minLength: 1, maxLength: 64 }),
  }, { additionalProperties: false }),
  validation: Type.Object({
    status: Type.Literal('eligible'),
    identity_verified: Type.Literal(true),
    simulation_allowed: Type.Literal(true),
  }, { additionalProperties: false }),
}, { additionalProperties: false });

export type PaperPositionRegistration = Static<typeof PaperPositionRegistrationSchema>;
export type PaperPositionObservation = Static<typeof PaperPositionObservationSchema>;
export const PaperPositionRegistrationResponseSchema = Type.Object({
  operationId: EngineIdSchema,
  positionId: EngineIdSchema,
  eventId: EngineIdSchema,
  status: Type.Literal('accepted'),
  duplicate: Type.Boolean(),
}, { additionalProperties: false });

export const PaperPositionObservationResponseSchema = Type.Object({
  operationId: EngineIdSchema,
  positionId: EngineIdSchema,
  decisionId: EngineIdSchema,
  eventId: EngineIdSchema,
  decision: Type.Union([Type.Literal('HOLD'), Type.Literal('EXIT')]),
  duplicate: Type.Boolean(),
}, { additionalProperties: false });

export type PaperPositionRegistrationResponse = Static<typeof PaperPositionRegistrationResponseSchema>;
export type PaperPositionObservationResponse = Static<typeof PaperPositionObservationResponseSchema>;
