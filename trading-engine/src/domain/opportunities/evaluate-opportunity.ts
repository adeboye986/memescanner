import {
  ETHEREUM_MAINNET_ID,
  type OpportunityCommand,
} from '../../contracts/http/opportunity-command.schema.js';
import { compareCanonicalDecimals } from '../../shared/amount/canonical-decimal.js';
import { hashCanonicalJson } from '../../shared/json/canonical-json.js';
import {
  type EvaluationAdvisoryCode,
  type EvaluationOutcome,
  type EvaluationProfile,
  type EvaluationReasonCode,
  OPPORTUNITY_EVALUATION_POLICY_V1,
  OPPORTUNITY_EVALUATION_POLICY_V1_SHA256,
} from './evaluation-policy.js';

type CheckStatus = 'failed' | 'indeterminate' | 'passed';
type CheckName =
  | 'market_cap'
  | 'liquidity'
  | 'volume_5m'
  | 'movement'
  | 'classification'
  | 'pair_validation'
  | 'security';

export interface EvaluationEvidence {
  readonly profile: EvaluationProfile;
  readonly facts: {
    readonly market_cap_usd: string | null;
    readonly liquidity_usd: string | null;
    readonly volume_5m_usd: string | null;
    readonly move_since_discovery_percent: string | null;
    readonly classification: string | null;
    readonly pair_address: string | null;
    readonly pair_available: boolean | null;
    readonly requested_token_is_base: boolean | null;
    readonly security_status: 'passed' | 'failed' | 'unavailable' | null;
    readonly security_provider: 'goplus' | 'solana_rpc_holder_analysis' | null;
    readonly security_passed: boolean | null;
  };
  readonly checks: readonly {
    readonly check: CheckName;
    readonly status: CheckStatus;
  }[];
}

export interface OpportunityEvaluationResult {
  readonly outcome: EvaluationOutcome;
  readonly reason_codes: readonly EvaluationReasonCode[];
  readonly advisory_codes: readonly EvaluationAdvisoryCode[];
  readonly evidence: EvaluationEvidence;
  readonly evaluation_input_sha256: string;
  readonly result_sha256: string;
}

interface Finding {
  readonly status: CheckStatus;
  readonly reason?: EvaluationReasonCode;
  readonly advisory?: EvaluationAdvisoryCode;
}

export function evaluateOpportunitySnapshot(
  opportunity: OpportunityCommand,
  sourceRequestSha256: string,
): OpportunityEvaluationResult {
  const profile = evaluationProfile(opportunity);
  const rules = OPPORTUNITY_EVALUATION_POLICY_V1.profiles[profile];
  const findings = new Map<CheckName, Finding>();
  const market = opportunity.market_snapshot;

  findings.set(
    'market_cap',
    boundedDecimal(
      market.market_cap_usd?.value,
      rules.market_cap_usd.minimum,
      rules.market_cap_usd.maximum,
      'MARKET_CAP_MISSING',
      'MARKET_CAP_BELOW_MINIMUM',
      'MARKET_CAP_ABOVE_MAXIMUM',
    ),
  );
  findings.set(
    'liquidity',
    minimumDecimal(
      market.liquidity_usd?.value,
      rules.liquidity_usd.minimum,
      'LIQUIDITY_MISSING',
      'LIQUIDITY_BELOW_MINIMUM',
    ),
  );

  if (rules.volume_5m_usd !== 'not-required') {
    const volume = market.volume_usd?.window === '5m'
      ? market.volume_usd.value
      : undefined;
    findings.set(
      'volume_5m',
      minimumDecimal(
        volume,
        rules.volume_5m_usd.minimum,
        'VOLUME_5M_MISSING',
        'VOLUME_5M_BELOW_MINIMUM',
      ),
    );
  }

  findings.set(
    'movement',
    movement(opportunity.qualification.move_since_discovery_percent),
  );

  if (rules.classification !== 'not-required') {
    findings.set(
      'classification',
      classification(opportunity.qualification.classification, rules.classification),
    );
  }

  if (rules.pair_validation !== 'not-required') {
    findings.set('pair_validation', pairValidation(opportunity));
  }

  findings.set('security', security(opportunity, rules.security));

  const reasons = orderedReasons(findings);
  const advisories = orderedAdvisories(findings);
  const checks = [...findings.entries()].map(([check, finding]) => ({
    check,
    status: finding.status,
  }));
  const evidence: EvaluationEvidence = {
    profile,
    facts: {
      market_cap_usd: market.market_cap_usd?.value ?? null,
      liquidity_usd: market.liquidity_usd?.value ?? null,
      volume_5m_usd: market.volume_usd?.window === '5m' ? market.volume_usd.value : null,
      move_since_discovery_percent:
        opportunity.qualification.move_since_discovery_percent ?? null,
      classification: opportunity.qualification.classification ?? null,
      pair_address: market.pair?.address ?? null,
      pair_available: opportunity.security?.market_validation?.pair_available ?? null,
      requested_token_is_base:
        opportunity.security?.market_validation?.requested_token_is_base ?? null,
      security_status: opportunity.security?.status ?? null,
      security_provider: opportunity.security?.provider ?? null,
      security_passed: opportunity.security?.passed ?? null,
    },
    checks,
  };
  const outcome = evaluationOutcome(findings);
  const evaluationInputSha256 = hashCanonicalJson({
    source_request_sha256: sourceRequestSha256,
    policy_definition_sha256: OPPORTUNITY_EVALUATION_POLICY_V1_SHA256,
  });
  const semanticResult = {
    policy_key: OPPORTUNITY_EVALUATION_POLICY_V1.policy_key,
    policy_version: OPPORTUNITY_EVALUATION_POLICY_V1.policy_version,
    algorithm_key: OPPORTUNITY_EVALUATION_POLICY_V1.algorithm_key,
    algorithm_version: OPPORTUNITY_EVALUATION_POLICY_V1.algorithm_version,
    policy_definition_sha256: OPPORTUNITY_EVALUATION_POLICY_V1_SHA256,
    source_request_sha256: sourceRequestSha256,
    evaluation_input_sha256: evaluationInputSha256,
    outcome,
    reason_codes: reasons,
    advisory_codes: advisories,
    evidence,
  };

  return {
    outcome,
    reason_codes: reasons,
    advisory_codes: advisories,
    evidence,
    evaluation_input_sha256: evaluationInputSha256,
    result_sha256: hashCanonicalJson(semanticResult),
  };
}

function evaluationProfile(opportunity: OpportunityCommand): EvaluationProfile {
  const network = opportunity.network.id === ETHEREUM_MAINNET_ID
    ? 'ethereum'
    : 'solana';

  return `${network}:${opportunity.source.scanner}`;
}

function boundedDecimal(
  value: string | undefined,
  minimum: string,
  maximum: string,
  missing: EvaluationReasonCode,
  below: EvaluationReasonCode,
  above: EvaluationReasonCode,
): Finding {
  if (value === undefined) {
    return { status: 'indeterminate', reason: missing };
  }

  if (compareCanonicalDecimals(value, minimum) < 0) {
    return { status: 'failed', reason: below };
  }

  if (compareCanonicalDecimals(value, maximum) > 0) {
    return { status: 'failed', reason: above };
  }

  return { status: 'passed' };
}

function minimumDecimal(
  value: string | undefined,
  minimum: string,
  missing: EvaluationReasonCode,
  below: EvaluationReasonCode,
): Finding {
  if (value === undefined) {
    return { status: 'indeterminate', reason: missing };
  }

  return compareCanonicalDecimals(value, minimum) < 0
    ? { status: 'failed', reason: below }
    : { status: 'passed' };
}

function movement(value: string | undefined): Finding {
  if (value === undefined) {
    return { status: 'indeterminate', reason: 'MOVEMENT_MISSING' };
  }

  if (compareCanonicalDecimals(value, '-30') <= 0) {
    return { status: 'failed', reason: 'MOVEMENT_AT_OR_BELOW_MINIMUM' };
  }

  return compareCanonicalDecimals(value, '35') > 0
    ? { status: 'failed', reason: 'MOVEMENT_ABOVE_MAXIMUM' }
    : { status: 'passed' };
}

function classification(value: string | undefined, required: 'strong'): Finding {
  if (value === undefined) {
    return { status: 'indeterminate', reason: 'CLASSIFICATION_MISSING' };
  }

  return value === required
    ? { status: 'passed' }
    : { status: 'failed', reason: 'CLASSIFICATION_NOT_STRONG' };
}

function pairValidation(opportunity: OpportunityCommand): Finding {
  const pair = opportunity.market_snapshot.pair;
  const validation = opportunity.security?.market_validation;

  if (pair === undefined || validation === undefined) {
    return { status: 'indeterminate', reason: 'PAIR_EVIDENCE_MISSING' };
  }

  return validation.pair_available && validation.requested_token_is_base
    ? { status: 'passed' }
    : { status: 'failed', reason: 'PAIR_VALIDATION_FAILED' };
}

function security(
  opportunity: OpportunityCommand,
  policy:
    | 'required-passed-goplus'
    | 'holder-failure-fails-unavailable-advisory'
    | 'unavailable-advisory',
): Finding {
  const evidence = opportunity.security;

  if (evidence !== undefined && contradictorySecurity(evidence)) {
    return { status: 'indeterminate', reason: 'SECURITY_EVIDENCE_CONTRADICTORY' };
  }

  if (policy === 'required-passed-goplus') {
    if (evidence === undefined || evidence.status === 'unavailable') {
      return { status: 'indeterminate', reason: 'SECURITY_EVIDENCE_MISSING' };
    }

    if (evidence.status === 'failed' || evidence.passed === false) {
      return { status: 'failed', reason: 'SECURITY_EVIDENCE_FAILED' };
    }

    return evidence.provider === 'goplus' && evidence.passed === true
      ? { status: 'passed' }
      : { status: 'indeterminate', reason: 'SECURITY_EVIDENCE_MISSING' };
  }

  if (evidence === undefined || evidence.status === 'unavailable') {
    return { status: 'passed', advisory: 'SECURITY_EVIDENCE_UNAVAILABLE' };
  }

  if (policy === 'holder-failure-fails-unavailable-advisory'
    && (evidence.status === 'failed' || evidence.passed === false)) {
    return { status: 'failed', reason: 'SECURITY_EVIDENCE_FAILED' };
  }

  return { status: 'passed' };
}

function contradictorySecurity(
  evidence: NonNullable<OpportunityCommand['security']>,
): boolean {
  return (evidence.status === 'passed' && evidence.passed === false)
    || (evidence.status === 'failed' && evidence.passed === true)
    || (evidence.status === 'unavailable' && evidence.passed !== undefined);
}

function orderedReasons(findings: ReadonlyMap<CheckName, Finding>): readonly EvaluationReasonCode[] {
  const present = new Set(
    [...findings.values()]
      .map((finding) => finding.reason)
      .filter((reason): reason is EvaluationReasonCode => reason !== undefined),
  );

  return OPPORTUNITY_EVALUATION_POLICY_V1.reason_order.filter((code) => present.has(code));
}

function orderedAdvisories(
  findings: ReadonlyMap<CheckName, Finding>,
): readonly EvaluationAdvisoryCode[] {
  const present = new Set(
    [...findings.values()]
      .map((finding) => finding.advisory)
      .filter((advisory): advisory is EvaluationAdvisoryCode => advisory !== undefined),
  );

  return OPPORTUNITY_EVALUATION_POLICY_V1.advisory_order.filter(
    (code) => present.has(code),
  );
}

function evaluationOutcome(findings: ReadonlyMap<CheckName, Finding>): EvaluationOutcome {
  const statuses = new Set([...findings.values()].map((finding) => finding.status));

  if (statuses.has('failed')) {
    return 'failed';
  }

  return statuses.has('indeterminate') ? 'indeterminate' : 'passed';
}
