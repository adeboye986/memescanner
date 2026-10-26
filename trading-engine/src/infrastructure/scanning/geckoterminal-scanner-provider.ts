import {
  ScannerProviderError,
  arrayValue,
  objectValue,
  stringValue,
} from './scanner-http-client.js';
import type { ScannerHttpClient } from './scanner-http-client.js';

export interface EthereumTokenProfile {
  readonly address: string;
}

export class GeckoTerminalScannerProvider {
  private readonly baseUrl = new URL('https://api.geckoterminal.com');

  public constructor(
    private readonly client: ScannerHttpClient,
    private readonly timeoutMs: number,
  ) {}

  public async latestEthereumTokens(
    limit = 30,
    signal?: AbortSignal,
  ): Promise<readonly EthereumTokenProfile[]> {
    const url = new URL('/api/v2/networks/eth/new_pools', this.baseUrl);
    url.searchParams.set('page', '1');
    const payload = objectValue(await this.client.json(url, {
      timeoutMs: this.timeoutMs,
      provider: 'geckoterminal',
      ...(signal === undefined ? {} : { signal }),
    }));
    const pools = arrayValue(payload?.['data']);

    if (pools === undefined) {
      throw new ScannerProviderError('PROVIDER_RESPONSE_MALFORMED', false, null, 'geckoterminal');
    }

    const maximum = Math.max(1, Math.min(limit, 30));
    const seen = new Set<string>();
    const profiles: EthereumTokenProfile[] = [];

    for (const raw of pools) {
      const pool = objectValue(raw);
      const relationships = objectValue(pool?.['relationships']);
      const baseToken = objectValue(relationships?.['base_token']);
      const data = objectValue(baseToken?.['data']);
      const id = stringValue(data?.['id'], 255);
      const match = id === undefined ? null : /0x[a-fA-F0-9]{40}/.exec(id);

      if (match === null) {
        continue;
      }

      const address = match[0].toLowerCase();

      if (!seen.has(address)) {
        seen.add(address);
        profiles.push({ address });
      }

      if (profiles.length >= maximum) {
        break;
      }
    }

    return profiles;
  }
}
