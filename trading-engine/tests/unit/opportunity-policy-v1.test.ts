import { describe, expect, it } from 'vitest';

import {
  ETHEREUM_MAINNET_ID,
  type OpportunityCommand,
} from '../../src/contracts/http/opportunity-command.schema.js';
import { evaluateOpportunitySnapshot } from '../../src/domain/opportunities/evaluate-opportunity.js';
import {
  OPPORTUNITY_EVALUATION_POLICY_V1,
  OPPORTUNITY_EVALUATION_POLICY_V1_SHA256,
} from '../../src/domain/opportunities/evaluation-policy.js';
import { hashCanonicalJson } from '../../src/shared/json/canonical-json.js';

const sourceHash = 'a'.repeat(64);

describe('opportunity evaluation policy v1 frozen vectors', () => {
  it.each([
    ['Ethereum new-token market-cap lower', ethereumNewToken, 'market_cap_usd', '2000'],
    ['Ethereum new-token market-cap upper', ethereumNewToken, 'market_cap_usd', '20000'],
    ['Ethereum new-token liquidity', ethereumNewToken, 'liquidity_usd', '500'],
    ['Ethereum momentum market-cap lower', ethereumMomentum, 'market_cap_usd', '5000'],
    ['Ethereum momentum market-cap upper', ethereumMomentum, 'market_cap_usd', '100000'],
    ['Ethereum momentum liquidity', ethereumMomentum, 'liquidity_usd', '1000'],
    ['Ethereum momentum five-minute volume', ethereumMomentum, 'volume_usd', '500'],
  ] as const)('passes the exact %s threshold', (_name, createCommand, field, value) => {
    const command = createCommand();

    if (field === 'volume_usd') {
      command.market_snapshot.volume_usd = {
        value,
        provider: 'dexscreener',
        window: '5m',
      };
    } else {
      command.market_snapshot[field] = {
        value,
        provider: 'dexscreener',
      };
    }

    expect(evaluateOpportunitySnapshot(command, sourceHash).outcome).toBe('passed');
  });

  it('freezes policy, input, and semantic result SHA-256 vectors', () => {
    const result = evaluateOpportunitySnapshot(ethereumMomentum(), sourceHash);

    expect(OPPORTUNITY_EVALUATION_POLICY_V1_SHA256).toBe(
      '7274b84fda5959c257a24fa585d5f4a6e00ad48c047a5ba44b9d109b12ace6dc',
    );
    expect(OPPORTUNITY_EVALUATION_POLICY_V1_SHA256).toBe(
      hashCanonicalJson(OPPORTUNITY_EVALUATION_POLICY_V1),
    );
    expect(result.evaluation_input_sha256).toBe(
      '6a625bd363898a374d8117cfd02e427536ba20db1a7cfde4004a109b05a01692',
    );
    expect(result.result_sha256).toBe(
      '1253455f2e6a32a51b70194a265c83b985e03c68cc0605ad3cf2b394f42723f3',
    );
  });
});

function ethereumNewToken(): OpportunityCommand {
  return {
    schema_version: 1,
    source: {
      system: 'meme-scanner-laravel',
      opportunity_id: '42',
      discovery_key: 'a'.repeat(64),
      scanner: 'new-token',
    },
    subject: { control_plane_user_id: '7' },
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
    qualification: {
      qualified_at: '2026-09-28T12:00:00.000Z',
      move_since_discovery_percent: '10',
      classification: 'strong',
    },
    security: {
      status: 'unavailable',
      market_validation: {
        provider: 'dexscreener',
        requested_token_is_base: true,
        pair_available: true,
      },
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
