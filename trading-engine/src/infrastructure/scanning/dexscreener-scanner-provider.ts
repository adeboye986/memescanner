import type { ScannerHttpClient } from './scanner-http-client.js';
import {
  ScannerProviderError,
  arrayValue,
  finiteNumber,
  objectValue,
  stringValue,
} from './scanner-http-client.js';

export interface DexProfile {
  readonly address: string;
}

export interface DexMarket {
  readonly address: string;
  readonly available: boolean;
  readonly requestedTokenIsBase: boolean;
  readonly symbol: string | null;
  readonly name: string | null;
  readonly priceUsd: number | null;
  readonly marketCap: number;
  readonly liquidityUsd: number | null;
  readonly volume5m: number;
  readonly buys5m: number;
  readonly sells5m: number;
  readonly priceChange5m: number | null;
  readonly pairAddress: string | null;
  readonly dex: string | null;
}

export class DexScreenerScannerProvider {
  private readonly baseUrl = new URL('https://api.dexscreener.com');

  public constructor(
    private readonly client: ScannerHttpClient,
    private readonly timeoutMs: number,
  ) {}

  public async latestProfiles(
    chain: 'solana' | 'ethereum',
    limit = 30,
    signal?: AbortSignal,
  ): Promise<readonly DexProfile[]> {
    const url = new URL('/token-profiles/latest/v1', this.baseUrl);
    const payload = arrayValue(await this.client.json(url, {
      timeoutMs: this.timeoutMs,
      ...(signal === undefined ? {} : { signal }),
    }));

    if (payload === undefined) {
      throw new ScannerProviderError('PROVIDER_RESPONSE_MALFORMED', false);
    }

    const seen = new Set<string>();
    const profiles: DexProfile[] = [];

    for (const raw of payload) {
      const profile = objectValue(raw);
      const address = stringValue(profile?.['tokenAddress'], 128);

      if (profile?.['chainId'] !== chain || address === undefined) {
        continue;
      }

      const key = chain === 'ethereum' ? address.toLowerCase() : address;

      if (!seen.has(key)) {
        seen.add(key);
        profiles.push({ address: key });
      }

      if (profiles.length >= Math.max(1, Math.min(limit, 50))) {
        break;
      }
    }

    return profiles;
  }

  public async market(
    address: string,
    chain: 'solana' | 'ethereum',
    signal?: AbortSignal,
  ): Promise<DexMarket> {
    const url = new URL(`/token-pairs/v1/${chain}/${address}`, this.baseUrl);
    const payload = arrayValue(await this.client.json(url, {
      timeoutMs: this.timeoutMs,
      ...(signal === undefined ? {} : { signal }),
    }));

    if (payload === undefined) {
      throw new ScannerProviderError('PROVIDER_RESPONSE_MALFORMED', false);
    }

    const equals = (left: string): boolean => chain === 'ethereum'
      ? left.toLowerCase() === address.toLowerCase()
      : left === address;
    const pairs = payload.flatMap((raw) => {
      const pair = objectValue(raw);
      const base = objectValue(pair?.['baseToken']);
      const quote = objectValue(pair?.['quoteToken']);
      const baseAddress = stringValue(base?.['address'], 128);
      const quoteAddress = stringValue(quote?.['address'], 128);

      const requestedTokenIsBase = baseAddress !== undefined && equals(baseAddress);
      const requestedTokenIsQuote = quoteAddress !== undefined && equals(quoteAddress);

      return pair === undefined || (!requestedTokenIsBase && !requestedTokenIsQuote)
        ? []
        : [pair];
    });
    const basePairs = pairs.filter((pair) => {
      const baseAddress = stringValue(objectValue(pair['baseToken'])?.['address'], 128);

      return baseAddress !== undefined && equals(baseAddress);
    });
    const candidates = basePairs.length > 0 ? basePairs : pairs;
    candidates.sort((left, right) => {
      const leftLiquidity = finiteNumber(objectValue(left['liquidity'])?.['usd']) ?? 0;
      const rightLiquidity = finiteNumber(objectValue(right['liquidity'])?.['usd']) ?? 0;

      return rightLiquidity - leftLiquidity;
    });
    const pair = candidates[0];

    if (pair === undefined) {
      return unavailable(address);
    }

    const base = objectValue(pair['baseToken']);
    const baseAddress = stringValue(base?.['address'], 128);
    const requestedTokenIsBase = baseAddress !== undefined && equals(baseAddress);
    const liquidity = objectValue(pair['liquidity']);
    const transactions = objectValue(objectValue(pair['txns'])?.['m5']);
    const volume = objectValue(pair['volume']);
    const priceChange = objectValue(pair['priceChange']);

    return {
      address,
      available: true,
      requestedTokenIsBase,
      symbol: requestedTokenIsBase ? stringValue(base?.['symbol'], 64) ?? null : null,
      name: requestedTokenIsBase ? stringValue(base?.['name'], 255) ?? null : null,
      priceUsd: requestedTokenIsBase ? finiteNumber(pair['priceUsd']) ?? null : null,
      marketCap: requestedTokenIsBase ? finiteNumber(pair['marketCap']) ?? 0 : 0,
      liquidityUsd: finiteNumber(liquidity?.['usd']) ?? null,
      volume5m: finiteNumber(volume?.['m5']) ?? 0,
      buys5m: Math.max(0, Math.trunc(finiteNumber(transactions?.['buys']) ?? 0)),
      sells5m: Math.max(0, Math.trunc(finiteNumber(transactions?.['sells']) ?? 0)),
      priceChange5m: requestedTokenIsBase ? finiteNumber(priceChange?.['m5']) ?? null : null,
      pairAddress: stringValue(pair['pairAddress'], 128) ?? null,
      dex: stringValue(pair['dexId'], 128) ?? null,
    };
  }
}

function unavailable(address: string): DexMarket {
  return {
    address,
    available: false,
    requestedTokenIsBase: false,
    symbol: null,
    name: null,
    priceUsd: null,
    marketCap: 0,
    liquidityUsd: null,
    volume5m: 0,
    buys5m: 0,
    sells5m: 0,
    priceChange5m: null,
    pairAddress: null,
    dex: null,
  };
}
