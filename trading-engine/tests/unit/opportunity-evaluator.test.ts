import { Value } from '@sinclair/typebox/value';
import { describe, expect, it } from 'vitest';

import { OpportunityEvaluatedPayloadSchema } from '../../src/contracts/events/opportunity-evaluated-event.schema.js';
import {
  ETHEREUM_MAINNET_ID,
  SOLANA_MAINNET_ID,
  type OpportunityCommand,
} from '../../src/contracts/http/opportunity-command.schema.js';
import { evaluateOpportunitySnapshot } from '../../src/domain/opportunities/evaluate-opportunity.js';
import {
  OPPORTUNITY_EVALUATION_POLICY_V1,
  OPPORTUNITY_EVALUATION_POLICY_V1_SHA256,
} from '../../src/domain/opportunities/evaluation-policy.js';
import { compareCanonicalDecimals } from '../../src/shared/amount/canonical-decimal.js';
import { hashCanonicalJson } from '../../src/shared/json/canonical-json.js';

const sourceHash = 'a'.repeat(64);

describe('opportunity snapshot evaluator', () => {
  it.each([
    ['market-cap lower boundary', 'market_cap_usd', '2000'],
    ['market-cap upper boundary', 'market_cap_usd', '20000'],
    ['liquidity boundary', 'liquidity_usd', '500'],
  ] as const)('passes Solana new-token %s exactly', (_name, field, value) => {
    const command = solanaNewToken();
    command.market_snapshot[field] = { value, provider: 'birdeye' };

    expect(evaluateOpportunitySnapshot(command, sourceHash).outcome).toBe('passed');
  });

  it.each([
    ['1999.999', 'MARKET_CAP_BELOW_MINIMUM'],
    ['20000.001', 'MARKET_CAP_ABOVE_MAXIMUM'],
  ] as const)('fails Solana new-token market cap %s', (value, reason) => {
    const command = solanaNewToken();
    command.market_snapshot.market_cap_usd = { value, provider: 'birdeye' };

    const result = evaluateOpportunitySnapshot(command, sourceHash);

    expect(result.outcome).toBe('failed');
    expect(result.reason_codes).toContain(reason);
  });

  it('fails liquidity immediately below the inclusive threshold', () => {
    const command = solanaNewToken();
    command.market_snapshot.liquidity_usd = { value: '499.999', provider: 'birdeye' };

    expect(evaluateOpportunitySnapshot(command, sourceHash)).toMatchObject({
      outcome: 'failed',
      reason_codes: ['LIQUIDITY_BELOW_MINIMUM'],
    });
  });

  it.each([
    ['-30', 'failed'],
    ['-29.999', 'passed'],
    ['35', 'passed'],
    ['35.001', 'failed'],
  ] as const)('applies the exact movement boundary %s', (value, outcome) => {
    const command = solanaNewToken();
    command.qualification.move_since_discovery_percent = value;

    expect(evaluateOpportunitySnapshot(command, sourceHash).outcome).toBe(outcome);
  });

  it('requires strong classification and passed GoPlus evidence for Solana new-token', () => {
    const classification = solanaNewToken();
    classification.qualification.classification = 'watchlist';
    const security = solanaNewToken();
    security.security = { status: 'unavailable' };

    expect(evaluateOpportunitySnapshot(classification, sourceHash)).toMatchObject({
      outcome: 'failed',
      reason_codes: ['CLASSIFICATION_NOT_STRONG'],
    });
    expect(evaluateOpportunitySnapshot(security, sourceHash)).toMatchObject({
      outcome: 'indeterminate',
      reason_codes: ['SECURITY_EVIDENCE_MISSING'],
    });
  });

  it.each([
    ['market-cap lower boundary', 'market_cap_usd', '5000'],
    ['market-cap upper boundary', 'market_cap_usd', '100000'],
    ['liquidity boundary', 'liquidity_usd', '1000'],
    ['five-minute volume boundary', 'volume_usd', '500'],
  ] as const)('passes Solana momentum %s exactly', (_name, field, value) => {
    const command = solanaMomentum();

    if (field === 'volume_usd') {
      command.market_snapshot.volume_usd = {
        value,
        provider: 'dexscreener',
        window: '5m',
      };
    } else {
      command.market_snapshot[field] = { value, provider: 'dexscreener' };
    }

    expect(evaluateOpportunitySnapshot(command, sourceHash).outcome).toBe('passed');
  });

  it('fails definitive holder security evidence and treats unavailable evidence as advisory', () => {
    const failed = solanaMomentum();
    failed.security = {
      ...failed.security,
      status: 'failed',
      passed: false,
    };
    const unavailable = solanaMomentum();
    unavailable.security = {
      status: 'unavailable',
      market_validation: validMarketValidation(),
    };

    expect(evaluateOpportunitySnapshot(failed, sourceHash)).toMatchObject({
      outcome: 'failed',
      reason_codes: ['SECURITY_EVIDENCE_FAILED'],
    });
    expect(evaluateOpportunitySnapshot(unavailable, sourceHash)).toMatchObject({
      outcome: 'passed',
      advisory_codes: ['SECURITY_EVIDENCE_UNAVAILABLE'],
    });
  });

  it('requires pair and requested-base evidence for momentum and Ethereum profiles', () => {
    for (const command of [solanaMomentum(), ethereumNewToken(), ethereumMomentum()]) {
      delete command.market_snapshot.pair;

      expect(evaluateOpportunitySnapshot(command, sourceHash)).toMatchObject({
        outcome: 'indeterminate',
        reason_codes: ['PAIR_EVIDENCE_MISSING'],
      });
    }
  });

  it('fails an explicitly invalid pair/base validation', () => {
    const command = ethereumMomentum();
    command.security = {
      status: 'unavailable',
      market_validation: {
        ...validMarketValidation(),
        requested_token_is_base: false,
      },
    };

    expect(evaluateOpportunitySnapshot(command, sourceHash)).toMatchObject({
      outcome: 'failed',
      reason_codes: ['PAIR_VALIDATION_FAILED'],
    });
  });

  it.each([
    ['Ethereum new-token', ethereumNewToken()],
    ['Ethereum momentum', ethereumMomentum()],
  ])('permits unavailable discovery security as an advisory for %s', (_name, command) => {
    expect(evaluateOpportunitySnapshot(command, sourceHash)).toMatchObject({
      outcome: 'passed',
      advisory_codes: ['SECURITY_EVIDENCE_UNAVAILABLE'],
    });
  });

  it('marks contradictory security fields indeterminate', () => {
    const command = ethereumMomentum();
    command.security = {
      status: 'unavailable',
      passed: true,
      market_validation: validMarketValidation(),
    };

    expect(evaluateOpportunitySnapshot(command, sourceHash)).toMatchObject({
      outcome: 'indeterminate',
      reason_codes: ['SECURITY_EVIDENCE_CONTRADICTORY'],
      advisory_codes: [],
    });
  });

  it('uses failed before indeterminate and freezes reason ordering', () => {
    const command = solanaNewToken();
    delete command.market_snapshot.market_cap_usd;
    command.market_snapshot.liquidity_usd = { value: '1', provider: 'birdeye' };
    delete command.qualification.move_since_discovery_percent;
    delete command.qualification.classification;
    command.security = { status: 'passed', passed: false, provider: 'goplus' };

    const result = evaluateOpportunitySnapshot(command, sourceHash);

    expect(result.outcome).toBe('failed');
    expect(result.reason_codes).toEqual([
      'MARKET_CAP_MISSING',
      'LIQUIDITY_BELOW_MINIMUM',
      'MOVEMENT_MISSING',
      'CLASSIFICATION_MISSING',
      'SECURITY_EVIDENCE_CONTRADICTORY',
    ]);
  });

  it('produces identical semantic output and hashes for identical input and policy', () => {
    const first = evaluateOpportunitySnapshot(ethereumMomentum(), sourceHash);
    const second = evaluateOpportunitySnapshot(ethereumMomentum(), sourceHash);

    expect(second).toEqual(first);
    expect(first.evaluation_input_sha256).toMatch(/^[0-9a-f]{64}$/);
    expect(first.result_sha256).toMatch(/^[0-9a-f]{64}$/);
    expect(OPPORTUNITY_EVALUATION_POLICY_V1_SHA256).toBe(
      hashCanonicalJson(OPPORTUNITY_EVALUATION_POLICY_V1),
    );
  });

  it('defines an event payload that excludes every execution authority field', () => {
    const result = evaluateOpportunitySnapshot(ethereumMomentum(), sourceHash);
    const payload = {
      evaluation_id: '01K9ZYXWVTSRQPNMKJHGFEDCBA',
      opportunity_id: '01K9ABCDEFGHJKMNPQRSTVWXYZ',
      policy: {
        key: OPPORTUNITY_EVALUATION_POLICY_V1.policy_key,
        version: 1,
        algorithm_key: OPPORTUNITY_EVALUATION_POLICY_V1.algorithm_key,
        algorithm_version: 1,
        definition_sha256: OPPORTUNITY_EVALUATION_POLICY_V1_SHA256,
      },
      source: {
        request_sha256: sourceHash,
        evaluation_input_sha256: result.evaluation_input_sha256,
      },
      outcome: result.outcome,
      reason_codes: result.reason_codes,
      advisory_codes: result.advisory_codes,
      evidence: result.evidence,
      result_sha256: result.result_sha256,
    };

    expect(Value.Check(OpportunityEvaluatedPayloadSchema, payload)).toBe(true);
    expect(JSON.stringify(payload)).not.toMatch(
      /execution_mode|entry_mode|buy|approval|authorization|amount|wallet|balance|order|position|signing|transaction/i,
    );
  });
});

describe('exact canonical decimal comparison', () => {
  it.each([
    ['0.00000000000000000000000000001', '0', 1],
    ['999999999999999999999999999999999999999999999999', '100000', 1],
    ['-30', '-29.99999999999999999999999999999', -1],
    ['500', '500', 0],
    ['-0.1', '-1', 1],
  ] as const)('compares %s with %s without floating point', (left, right, expected) => {
    expect(compareCanonicalDecimals(left, right)).toBe(expected);
  });
});

function solanaNewToken(): OpportunityCommand {
  return {
    schema_version: 1,
    source: {
      system: 'meme-scanner-laravel',
      opportunity_id: '42',
      discovery_key: 'a'.repeat(64),
      scanner: 'new-token',
    },
    subject: { control_plane_user_id: '7' },
    network: { id: SOLANA_MAINNET_ID },
    asset: { address: 'So11111111111111111111111111111111111111112' },
    market_snapshot: {
      market_cap_usd: { value: '10000', provider: 'birdeye' },
      liquidity_usd: { value: '1000', provider: 'birdeye' },
    },
    qualification: {
      qualified_at: '2026-09-28T12:00:00.000Z',
      move_since_discovery_percent: '10',
      classification: 'strong',
    },
    security: {
      status: 'passed',
      provider: 'goplus',
      passed: true,
      risks: [],
    },
  };
}

function solanaMomentum(): OpportunityCommand {
  const command = solanaNewToken();

  return {
    ...command,
    source: { ...command.source, scanner: 'momentum' },
    market_snapshot: {
      market_cap_usd: { value: '10000', provider: 'dexscreener' },
      liquidity_usd: { value: '2000', provider: 'dexscreener' },
      volume_usd: { value: '1000', provider: 'dexscreener', window: '5m' },
      pair: {
        address: '9xQeWvG816bUx9EPjHmaT23yvVMV84LkQg21qZC5YQ9',
        provider: 'dexscreener',
      },
    },
    qualification: command.qualification,
    security: {
      status: 'passed',
      provider: 'solana_rpc_holder_analysis',
      passed: true,
      market_validation: validMarketValidation(),
    },
  };
}

function ethereumNewToken(): OpportunityCommand {
  const command = solanaNewToken();

  return {
    ...command,
    network: { id: ETHEREUM_MAINNET_ID },
    asset: { address: '0x1111111111111111111111111111111111111111' },
    market_snapshot: {
      market_cap_usd: { value: '10000', provider: 'dexscreener' },
      liquidity_usd: { value: '1000', provider: 'dexscreener' },
      pair: {
        address: '0x2222222222222222222222222222222222222222',
        provider: 'dexscreener',
      },
    },
    qualification: command.qualification,
    security: {
      status: 'unavailable',
      market_validation: validMarketValidation(),
    },
  };
}

function ethereumMomentum(): OpportunityCommand {
  const command = ethereumNewToken();

  return {
    ...command,
    source: { ...command.source, scanner: 'momentum' },
    market_snapshot: {
      ...command.market_snapshot,
      market_cap_usd: { value: '10000', provider: 'dexscreener' },
      liquidity_usd: { value: '2000', provider: 'dexscreener' },
      volume_usd: { value: '1000', provider: 'dexscreener', window: '5m' },
    },
  };
}

function validMarketValidation(): {
  provider: 'dexscreener';
  requested_token_is_base: true;
  pair_available: true;
} {
  return {
    provider: 'dexscreener',
    requested_token_is_base: true,
    pair_available: true,
  };
}
