import { readFile } from 'node:fs/promises';

import { describe, expect, it } from 'vitest';

import {
  calculateDexDiscoveryRank,
  calculateNewTokenScore,
  calculatePreBirdeyeScore,
  qualifiesEthereum,
  qualifiesMomentumDiscovery,
  qualifyNewToken,
} from '../../src/domain/scanning/scanner-qualification.js';
import type { BirdeyeOverview } from '../../src/infrastructure/scanning/birdeye-scanner-provider.js';
import type { DexMarket } from '../../src/infrastructure/scanning/dexscreener-scanner-provider.js';
import type { HolderRisk } from '../../src/infrastructure/scanning/solana-rpc-scanner-provider.js';

interface Fixture {
  readonly newToken: readonly {
    readonly market: BirdeyeOverview;
    readonly securityAvailable: boolean;
    readonly securityPassed: boolean | null;
    readonly expected: { readonly score: number; readonly qualified: boolean; readonly reasonCode: string };
  }[];
  readonly momentum: {
    readonly market: DexMarket;
    readonly holderRisk: HolderRisk;
    readonly expected: { readonly qualified: boolean; readonly dexRank: number; readonly preBirdeyeScore: number };
  };
  readonly ethereum: {
    readonly market: DexMarket;
    readonly expected: { readonly newToken: boolean; readonly momentum: boolean };
  };
}

const fixture = JSON.parse(
  await readFile(new URL('../fixtures/scanner-parity.json', import.meta.url), 'utf8'),
) as Fixture;

describe('Laravel scanner parity fixtures', () => {
  it.each(fixture.newToken)('preserves new-token qualification for $market.symbol', (entry) => {
    const result = qualifyNewToken(entry.market, entry.securityAvailable, entry.securityPassed);

    expect(calculateNewTokenScore(entry.market)).toBe(entry.expected.score);
    expect(result.qualified).toBe(entry.expected.qualified);
    expect(result.reasonCode).toBe(entry.expected.reasonCode);
  });

  it('preserves momentum discovery and ranking thresholds', () => {
    expect(qualifiesMomentumDiscovery(fixture.momentum.market)).toBe(
      fixture.momentum.expected.qualified,
    );
    expect(calculateDexDiscoveryRank(fixture.momentum.market)).toBe(
      fixture.momentum.expected.dexRank,
    );
    expect(calculatePreBirdeyeScore(
      fixture.momentum.market,
      fixture.momentum.holderRisk,
    )).toBe(fixture.momentum.expected.preBirdeyeScore);
  });

  it('preserves relevant Ethereum qualification thresholds', () => {
    expect(qualifiesEthereum('new-token', fixture.ethereum.market)).toBe(
      fixture.ethereum.expected.newToken,
    );
    expect(qualifiesEthereum('momentum', fixture.ethereum.market)).toBe(
      fixture.ethereum.expected.momentum,
    );
  });
});
