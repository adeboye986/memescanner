import { Type, type Static } from '@sinclair/typebox';

import {
  ETHEREUM_MAINNET_ID,
  SOLANA_MAINNET_ID,
} from './opportunity-command.schema.js';
import { NonNegativeDecimalStringSchema } from '../../shared/amount/canonical-decimal.js';

export const TokenScanCommandSchema = Type.Object(
  {
    schema_version: Type.Literal(1),
    source: Type.Object(
      {
        system: Type.Literal('meme-scanner-laravel'),
        activity_id: Type.String({ pattern: '^[1-9][0-9]{0,63}$' }),
      },
      { additionalProperties: false },
    ),
    subject: Type.Object(
      {
        control_plane_user_id: Type.String({ pattern: '^[1-9][0-9]{0,63}$' }),
      },
      { additionalProperties: false },
    ),
    scanner: Type.Union([Type.Literal('new-token'), Type.Literal('momentum')]),
    network: Type.Object(
      {
        id: Type.Union([
          Type.Literal(SOLANA_MAINNET_ID),
          Type.Literal(ETHEREUM_MAINNET_ID),
        ]),
      },
      { additionalProperties: false },
    ),
    policy: Type.Object(
      {
        max_chase_percent: NonNegativeDecimalStringSchema,
        momentum_birdeye_budget: Type.Integer({ minimum: 0, maximum: 20 }),
      },
      { additionalProperties: false },
    ),
  },
  { additionalProperties: false },
);

export type TokenScanCommand = Static<typeof TokenScanCommandSchema>;

export const TokenScanCommandResponseSchema = Type.Object(
  {
    operationId: Type.String({ minLength: 26, maxLength: 26 }),
    scanExecutionId: Type.String({ minLength: 26, maxLength: 26 }),
    status: Type.Literal('accepted'),
    duplicate: Type.Boolean(),
  },
  { additionalProperties: false },
);

export type TokenScanCommandResponse = Static<typeof TokenScanCommandResponseSchema>;
