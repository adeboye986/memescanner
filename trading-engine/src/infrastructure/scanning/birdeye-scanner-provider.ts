import type { ScannerHttpClient } from './scanner-http-client.js';
import {
  ScannerProviderError,
  arrayValue,
  finiteNumber,
  objectValue,
  stringValue,
} from './scanner-http-client.js';

export interface BirdeyeListing {
  readonly address: string;
  readonly symbol: string | null;
  readonly name: string | null;
  readonly liquidity: number;
}

export interface BirdeyeOverview {
  readonly address: string;
  readonly symbol: string | null;
  readonly name: string | null;
  readonly price: number | null;
  readonly marketCap: number;
  readonly liquidity: number;
  readonly holders: number;
  readonly volume1m: number;
  readonly buys1m: number;
  readonly sells1m: number;
  readonly uniqueWallets5m: number;
  readonly priceChange5mPercent: number;
}

export class BirdeyeScannerProvider {
  public constructor(
    private readonly client: ScannerHttpClient,
    private readonly baseUrl: URL,
    private readonly apiKey: string,
    private readonly timeoutMs: number,
  ) {}

  public async newListings(limit = 20, signal?: AbortSignal): Promise<readonly BirdeyeListing[]> {
    const url = new URL('/defi/v2/tokens/new_listing', this.baseUrl);
    url.searchParams.set('limit', String(Math.max(1, Math.min(limit, 20))));
    url.searchParams.set('meme_platform_enabled', 'true');
    const payload = objectValue(await this.request(url, signal));
    const data = objectValue(payload?.['data']);
    const items = arrayValue(data?.['items']);

    if (items === undefined) {
      throw new ScannerProviderError('PROVIDER_RESPONSE_MALFORMED', false);
    }

    const seen = new Set<string>();
    const listings: BirdeyeListing[] = [];

    for (const raw of items) {
      const item = objectValue(raw);
      const address = stringValue(item?.['address'], 128);

      if (address === undefined || seen.has(address)) {
        continue;
      }

      seen.add(address);
      listings.push({
        address,
        symbol: stringValue(item?.['symbol'], 64) ?? null,
        name: stringValue(item?.['name'], 255) ?? null,
        liquidity: finiteNumber(item?.['liquidity']) ?? 0,
      });
    }

    return listings;
  }

  public async overview(address: string, signal?: AbortSignal): Promise<BirdeyeOverview> {
    const url = new URL('/defi/token_overview', this.baseUrl);
    url.searchParams.set('address', address);
    const payload = objectValue(await this.request(url, signal));
    const data = objectValue(payload?.['data']);

    if (data === undefined) {
      throw new ScannerProviderError('PROVIDER_RESPONSE_MALFORMED', false);
    }

    return {
      address,
      symbol: stringValue(data['symbol'], 64) ?? null,
      name: stringValue(data['name'], 255) ?? null,
      price: finiteNumber(data['price']) ?? null,
      marketCap: finiteNumber(data['marketCap']) ?? 0,
      liquidity: finiteNumber(data['liquidity']) ?? 0,
      holders: Math.max(0, Math.trunc(finiteNumber(data['holder']) ?? 0)),
      volume1m: finiteNumber(data['v1m']) ?? 0,
      buys1m: Math.max(0, Math.trunc(finiteNumber(data['buy1m']) ?? 0)),
      sells1m: Math.max(0, Math.trunc(finiteNumber(data['sell1m']) ?? 0)),
      uniqueWallets5m: Math.max(0, Math.trunc(finiteNumber(data['uniqueWallet5m']) ?? 0)),
      priceChange5mPercent: finiteNumber(data['priceChange5mPercent']) ?? 0,
    };
  }

  private request(url: URL, signal?: AbortSignal): Promise<unknown> {
    return this.client.json(url, {
      headers: {
        'X-API-KEY': this.apiKey,
        'x-chain': 'solana',
      },
      timeoutMs: this.timeoutMs,
      ...(signal === undefined ? {} : { signal }),
    });
  }
}
