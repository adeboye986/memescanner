import { Type, type Static } from '@sinclair/typebox';

import { NonNegativeDecimalStringSchema } from '../../shared/amount/canonical-decimal.js';
import { SOLANA_MAINNET_ID } from './opportunity-command.schema.js';

const EngineIdSchema = Type.String({ pattern: '^[0-9A-HJKMNP-TV-Z]{26}$' });
const PositiveIdSchema = Type.String({ pattern: '^[1-9][0-9]*$' });
const HashSchema = Type.String({ pattern: '^[0-9a-f]{64}$' });

export const PaperEntryCommandSchema = Type.Object({
  schema_version: Type.Literal(1),
  source: Type.Object({
    system: Type.Literal('meme-scanner-laravel'),
    trade_opportunity_id: PositiveIdSchema,
    engine_opportunity_id: EngineIdSchema,
    evaluation_id: EngineIdSchema,
    evaluation_result_sha256: HashSchema,
  }, { additionalProperties: false }),
  subject: Type.Object({
    control_plane_user_id: PositiveIdSchema,
  }, { additionalProperties: false }),
  network: Type.Object({
    id: Type.Literal(SOLANA_MAINNET_ID),
    native_currency: Type.Literal('SOL'),
  }, { additionalProperties: false }),
  asset: Type.Object({
    address: Type.String({ minLength: 32, maxLength: 44 }),
    symbol: Type.Optional(Type.String({ minLength: 1, maxLength: 32 })),
  }, { additionalProperties: false }),
  entry: Type.Object({
    requested_notional_native: NonNegativeDecimalStringSchema,
    market_cap_usd: NonNegativeDecimalStringSchema,
    price_usd: NonNegativeDecimalStringSchema,
    liquidity_usd: Type.Optional(NonNegativeDecimalStringSchema),
    intent_created_at: Type.String({ format: 'date-time' }),
    expires_at: Type.String({ format: 'date-time' }),
  }, { additionalProperties: false }),
  authority: Type.Object({
    execution_mode: Type.Literal('paper'),
    entry_mode: Type.Literal('auto'),
    trading_enabled: Type.Literal(true),
    preference_version: Type.String({ minLength: 1, maxLength: 128 }),
    kill_switch_engaged: Type.Literal(false),
    kill_switch_version: Type.String({ minLength: 1, maxLength: 128 }),
    strategy: Type.Object({
      stop_loss_percent: NonNegativeDecimalStringSchema,
      protection_level_1_percent: NonNegativeDecimalStringSchema,
      protection_level_2_percent: NonNegativeDecimalStringSchema,
    }, { additionalProperties: false }),
    risk: Type.Object({
      trade_size_native: NonNegativeDecimalStringSchema,
      source: Type.Literal('laravel-control-plane'),
    }, { additionalProperties: false }),
    effective_policy: Type.Object({
      key: Type.Literal('engine-paper-entry'),
      version: Type.Literal(1),
    }, { additionalProperties: false }),
  }, { additionalProperties: false }),
}, { additionalProperties: false });

export const PaperEntryCommandResponseSchema = Type.Object({
  operationId: EngineIdSchema,
  walletId: EngineIdSchema,
  intentId: EngineIdSchema,
  orderId: EngineIdSchema,
  fillId: EngineIdSchema,
  positionId: EngineIdSchema,
  eventId: EngineIdSchema,
  status: Type.Literal('accepted'),
  duplicate: Type.Boolean(),
}, { additionalProperties: false });

export type PaperEntryCommandBody = Static<typeof PaperEntryCommandSchema>;
export type PaperEntryCommandResponse = Static<typeof PaperEntryCommandResponseSchema>;
