import { hashCanonicalJson } from '../../shared/json/canonical-json.js';

export const OPPORTUNITY_EVALUATION_POLICY_V1 = {
  policy_key: 'migration-opportunity-snapshot',
  policy_version: 1,
  algorithm_key: 'threshold-matrix',
  algorithm_version: 1,
  outcome_precedence: ['failed', 'indeterminate', 'passed'],
  reason_order: [
    'MARKET_CAP_MISSING',
    'MARKET_CAP_BELOW_MINIMUM',
    'MARKET_CAP_ABOVE_MAXIMUM',
    'LIQUIDITY_MISSING',
    'LIQUIDITY_BELOW_MINIMUM',
    'VOLUME_5M_MISSING',
    'VOLUME_5M_BELOW_MINIMUM',
    'MOVEMENT_MISSING',
    'MOVEMENT_AT_OR_BELOW_MINIMUM',
    'MOVEMENT_ABOVE_MAXIMUM',
    'CLASSIFICATION_MISSING',
    'CLASSIFICATION_NOT_STRONG',
    'PAIR_EVIDENCE_MISSING',
    'PAIR_VALIDATION_FAILED',
    'SECURITY_EVIDENCE_MISSING',
    'SECURITY_EVIDENCE_FAILED',
    'SECURITY_EVIDENCE_CONTRADICTORY',
  ],
  advisory_order: ['SECURITY_EVIDENCE_UNAVAILABLE'],
  profiles: {
    'solana:new-token': {
      market_cap_usd: { minimum: '2000', maximum: '20000' },
      liquidity_usd: { minimum: '500' },
      movement_percent: { exclusive_minimum: '-30', maximum: '35' },
      classification: 'strong',
      security: 'required-passed-goplus',
      pair_validation: 'not-required',
      volume_5m_usd: 'not-required',
    },
    'solana:momentum': {
      market_cap_usd: { minimum: '5000', maximum: '100000' },
      liquidity_usd: { minimum: '1000' },
      movement_percent: { exclusive_minimum: '-30', maximum: '35' },
      classification: 'not-required',
      security: 'holder-failure-fails-unavailable-advisory',
      pair_validation: 'required-pair-and-base',
      volume_5m_usd: { minimum: '500' },
    },
    'ethereum:new-token': {
      market_cap_usd: { minimum: '2000', maximum: '20000' },
      liquidity_usd: { minimum: '500' },
      movement_percent: { exclusive_minimum: '-30', maximum: '35' },
      classification: 'not-required',
      security: 'unavailable-advisory',
      pair_validation: 'required-pair-and-base',
      volume_5m_usd: 'not-required',
    },
    'ethereum:momentum': {
      market_cap_usd: { minimum: '5000', maximum: '100000' },
      liquidity_usd: { minimum: '1000' },
      movement_percent: { exclusive_minimum: '-30', maximum: '35' },
      classification: 'not-required',
      security: 'unavailable-advisory',
      pair_validation: 'required-pair-and-base',
      volume_5m_usd: { minimum: '500' },
    },
  },
} as const;

export type OpportunityEvaluationPolicyV1 = typeof OPPORTUNITY_EVALUATION_POLICY_V1;
export type EvaluationOutcome = 'failed' | 'indeterminate' | 'passed';
export type EvaluationReasonCode = OpportunityEvaluationPolicyV1['reason_order'][number];
export type EvaluationAdvisoryCode = OpportunityEvaluationPolicyV1['advisory_order'][number];
export type EvaluationProfile = keyof OpportunityEvaluationPolicyV1['profiles'];

export const OPPORTUNITY_EVALUATION_POLICY_V1_SHA256 = hashCanonicalJson(
  OPPORTUNITY_EVALUATION_POLICY_V1,
);
