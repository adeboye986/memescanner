import { Type, type Static } from '@sinclair/typebox';

const HashSchema = Type.String({ pattern: '^[0-9a-f]{64}$' });
const NullableDecimalSchema = Type.Union([
  Type.Null(),
  Type.String({ pattern: '^-?(?:0|[1-9][0-9]{0,47})(?:\\.[0-9]{0,29}[1-9])?$' }),
]);
const OutcomeSchema = Type.Union([
  Type.Literal('passed'),
  Type.Literal('failed'),
  Type.Literal('indeterminate'),
]);
const CheckSchema = Type.Union([
  Type.Literal('market_cap'),
  Type.Literal('liquidity'),
  Type.Literal('volume_5m'),
  Type.Literal('movement'),
  Type.Literal('classification'),
  Type.Literal('pair_validation'),
  Type.Literal('security'),
]);
const ReasonSchema = Type.Union([
  Type.Literal('MARKET_CAP_MISSING'),
  Type.Literal('MARKET_CAP_BELOW_MINIMUM'),
  Type.Literal('MARKET_CAP_ABOVE_MAXIMUM'),
  Type.Literal('LIQUIDITY_MISSING'),
  Type.Literal('LIQUIDITY_BELOW_MINIMUM'),
  Type.Literal('VOLUME_5M_MISSING'),
  Type.Literal('VOLUME_5M_BELOW_MINIMUM'),
  Type.Literal('MOVEMENT_MISSING'),
  Type.Literal('MOVEMENT_AT_OR_BELOW_MINIMUM'),
  Type.Literal('MOVEMENT_ABOVE_MAXIMUM'),
  Type.Literal('CLASSIFICATION_MISSING'),
  Type.Literal('CLASSIFICATION_NOT_STRONG'),
  Type.Literal('PAIR_EVIDENCE_MISSING'),
  Type.Literal('PAIR_VALIDATION_FAILED'),
  Type.Literal('SECURITY_EVIDENCE_MISSING'),
  Type.Literal('SECURITY_EVIDENCE_FAILED'),
  Type.Literal('SECURITY_EVIDENCE_CONTRADICTORY'),
]);

export const OpportunityEvaluatedPayloadSchema = Type.Object(
  {
    evaluation_id: Type.String({ minLength: 26, maxLength: 26 }),
    opportunity_id: Type.String({ minLength: 26, maxLength: 26 }),
    policy: Type.Object(
      {
        key: Type.Literal('migration-opportunity-snapshot'),
        version: Type.Literal(1),
        algorithm_key: Type.Literal('threshold-matrix'),
        algorithm_version: Type.Literal(1),
        definition_sha256: HashSchema,
      },
      { additionalProperties: false },
    ),
    source: Type.Object(
      {
        request_sha256: HashSchema,
        evaluation_input_sha256: HashSchema,
      },
      { additionalProperties: false },
    ),
    outcome: OutcomeSchema,
    reason_codes: Type.Array(ReasonSchema),
    advisory_codes: Type.Array(Type.Literal('SECURITY_EVIDENCE_UNAVAILABLE')),
    evidence: Type.Object(
      {
        profile: Type.Union([
          Type.Literal('solana:new-token'),
          Type.Literal('solana:momentum'),
          Type.Literal('ethereum:new-token'),
          Type.Literal('ethereum:momentum'),
        ]),
        facts: Type.Object(
          {
            market_cap_usd: NullableDecimalSchema,
            liquidity_usd: NullableDecimalSchema,
            volume_5m_usd: NullableDecimalSchema,
            move_since_discovery_percent: NullableDecimalSchema,
            classification: Type.Union([Type.Null(), Type.String({ minLength: 1, maxLength: 64 })]),
            pair_address: Type.Union([Type.Null(), Type.String({ minLength: 1, maxLength: 128 })]),
            pair_available: Type.Union([Type.Null(), Type.Boolean()]),
            requested_token_is_base: Type.Union([Type.Null(), Type.Boolean()]),
            security_status: Type.Union([
              Type.Null(),
              Type.Literal('passed'),
              Type.Literal('failed'),
              Type.Literal('unavailable'),
            ]),
            security_provider: Type.Union([
              Type.Null(),
              Type.Literal('goplus'),
              Type.Literal('solana_rpc_holder_analysis'),
            ]),
            security_passed: Type.Union([Type.Null(), Type.Boolean()]),
          },
          { additionalProperties: false },
        ),
        checks: Type.Array(Type.Object(
          {
            check: CheckSchema,
            status: OutcomeSchema,
          },
          { additionalProperties: false },
        )),
      },
      { additionalProperties: false },
    ),
    result_sha256: HashSchema,
  },
  { additionalProperties: false },
);

export type OpportunityEvaluatedPayload = Static<typeof OpportunityEvaluatedPayloadSchema>;
