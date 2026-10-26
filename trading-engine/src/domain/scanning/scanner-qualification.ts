import type { BirdeyeOverview } from '../../infrastructure/scanning/birdeye-scanner-provider.js';
import type { DexMarket } from '../../infrastructure/scanning/dexscreener-scanner-provider.js';
import type { HolderRisk } from '../../infrastructure/scanning/solana-rpc-scanner-provider.js';

export interface NewTokenQualification {
  readonly qualified: boolean;
  readonly reasonCode: string;
  readonly score: number;
  readonly classification: 'strong' | 'watchlist' | 'unverified';
}

export function calculateNewTokenScore(token: BirdeyeOverview): number {
  let score = 0;

  if (token.marketCap > 0) {
    const ratio = token.liquidity / token.marketCap;
    score += ratio >= 0.5 ? 20 : ratio >= 0.25 ? 10 : 0;
  }

  score += token.holders >= 50 ? 20 : token.holders >= 20 ? 15 : token.holders >= 10 ? 10 : 0;
  const trades = token.buys1m + token.sells1m;

  if (token.buys1m > 0 && trades > 0) {
    const ratio = token.buys1m / trades;
    score += ratio >= 0.7 ? 25 : ratio >= 0.6 ? 15 : ratio >= 0.5 ? 5 : 0;
  }

  score += token.uniqueWallets5m >= 30
    ? 20
    : token.uniqueWallets5m >= 15
      ? 15
      : token.uniqueWallets5m >= 10 ? 10 : 0;
  const change = token.priceChange5mPercent;
  score += change >= 2 && change <= 20
    ? 15
    : change > 0 && change < 2
      ? 5
      : change > 50
        ? -10
        : change <= -50
          ? -25
          : change <= -20
            ? -15
            : change <= -5 ? -10 : 0;

  return clamp(score);
}

export function qualifyNewToken(
  token: BirdeyeOverview,
  securityAvailable: boolean,
  securityPassed: boolean | null,
): NewTokenQualification {
  const score = calculateNewTokenScore(token);

  if (token.marketCap <= 0) {
    return result(false, 'MARKET_CAP_MISSING', score, 'watchlist');
  }
  if (token.marketCap < 2_000 || token.marketCap > 20_000) {
    return result(false, 'MARKET_CAP_OUTSIDE_RANGE', score, 'watchlist');
  }
  if (token.liquidity < 500) {
    return result(false, 'LIQUIDITY_BELOW_MINIMUM', score, 'watchlist');
  }
  if (token.holders < 5) {
    return result(false, 'HOLDERS_BELOW_MINIMUM', score, 'watchlist');
  }
  if (!securityAvailable) {
    return result(false, 'SECURITY_UNAVAILABLE', score, 'unverified');
  }
  if (securityPassed !== true) {
    return result(false, 'SECURITY_FAILED', score, 'watchlist');
  }
  if (score < 50) {
    return result(false, 'SCORE_BELOW_STRONG_THRESHOLD', score, 'watchlist');
  }

  return result(true, 'QUALIFIED', score, 'strong');
}

export function qualifiesEthereum(scanner: 'new-token' | 'momentum', market: DexMarket): boolean {
  if (!market.available || !market.requestedTokenIsBase) {
    return false;
  }

  return scanner === 'new-token'
    ? market.marketCap >= 2_000 && market.marketCap <= 20_000
      && (market.liquidityUsd ?? 0) >= 500
    : market.marketCap >= 5_000 && market.marketCap <= 100_000
      && (market.liquidityUsd ?? 0) >= 1_000 && market.volume5m >= 500;
}

export function qualifiesMomentumDiscovery(market: DexMarket): boolean {
  const trades = market.buys5m + market.sells5m;

  return market.available
    && market.requestedTokenIsBase
    && market.marketCap >= 5_000
    && market.marketCap <= 100_000
    && (market.liquidityUsd === null || market.liquidityUsd >= 1_000)
    && market.volume5m >= 500
    && trades >= 10
    && (market.priceChange5m === null
      || (market.priceChange5m > -40 && market.priceChange5m <= 150));
}

export function calculateDexDiscoveryRank(market: DexMarket): number {
  let score = 0;
  const marketCap = Math.max(1, market.marketCap);
  const ratio = market.volume5m / marketCap;
  score += ratio >= 0.5 ? 35 : ratio >= 0.25 ? 30 : ratio >= 0.15 ? 24
    : ratio >= 0.1 ? 18 : ratio >= 0.05 ? 10 : 5;
  const trades = market.buys5m + market.sells5m;
  score += trades >= 120 ? 25 : trades >= 80 ? 22 : trades >= 50 ? 18
    : trades >= 30 ? 14 : trades >= 20 ? 10 : 5;

  if (trades > 0) {
    const buyRatio = market.buys5m / trades;
    score += buyRatio >= 0.7 ? 20 : buyRatio >= 0.6 ? 16 : buyRatio >= 0.55 ? 12
      : buyRatio >= 0.5 ? 8 : buyRatio >= 0.4 ? 3 : 0;
  }

  if (market.priceChange5m !== null) {
    const change = market.priceChange5m;
    score += change >= 2 && change <= 20 ? 15 : change > 20 && change <= 40 ? 10
      : change > 0 && change < 2 ? 7 : change > 40 && change <= 80 ? 4
        : change <= -20 ? -10 : change < 0 ? -4 : 0;
  }

  const liquidity = market.liquidityUsd ?? 0;
  score += liquidity >= 10_000 ? 5 : liquidity >= 5_000 ? 4 : liquidity >= 1_000 ? 2 : 0;

  return clamp(score);
}

export function calculatePreBirdeyeScore(
  market: DexMarket,
  holderRisk: HolderRisk,
): number {
  const holderScore = holderRisk.score ?? 50;
  const overheatPenalty = market.priceChange5m !== null && market.priceChange5m > 100
    ? 15
    : 0;

  return clamp(Math.round(calculateDexDiscoveryRank(market) * 0.7 + holderScore * 0.3) - overheatPenalty);
}

function result(
  qualified: boolean,
  reasonCode: string,
  score: number,
  classification: NewTokenQualification['classification'],
): NewTokenQualification {
  return { qualified, reasonCode, score, classification };
}

function clamp(score: number): number {
  return Math.max(0, Math.min(score, 100));
}
