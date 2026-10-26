import type { ScannerHttpClient } from './scanner-http-client.js';
import {
  ScannerProviderError,
  objectValue,
} from './scanner-http-client.js';

export interface ScannerSecurityResult {
  readonly available: boolean;
  readonly passed: boolean | null;
  readonly score: number | null;
  readonly risks: readonly string[];
  readonly checks: Readonly<Record<string, string>>;
}

const ethereumFlags = {
  is_open_source: '1',
  is_mintable: '0',
  owner_change_balance: '0',
  transfer_pausable: '0',
  is_honeypot: '0',
  cannot_buy: '0',
  cannot_sell_all: '0',
} as const;

export class GoPlusScannerProvider {
  private readonly baseUrl = new URL('https://api.gopluslabs.io/api/v1');

  public constructor(
    private readonly client: ScannerHttpClient,
    private readonly timeoutMs: number,
    private readonly accessToken?: string,
  ) {}

  public async solana(address: string, signal?: AbortSignal): Promise<ScannerSecurityResult> {
    const url = new URL('/api/v1/solana/token_security', this.baseUrl);
    url.searchParams.set('contract_addresses', address);
    const payload = objectValue(await this.request(url, signal));
    const data = objectValue(objectValue(payload?.['result'])?.[address]);

    if (data === undefined) {
      return unavailable();
    }

    const risks: string[] = [];
    let score = 100;
    const status = (field: string): string => objectValue(data[field])?.['status'] === '1' ? '1' : '0';
    const penalize = (field: string, label: string, points: number): void => {
      if (status(field) === '1') {
        risks.push(label);
        score -= points;
      }
    };
    penalize('mintable', 'Token is mintable', 30);
    penalize('freezable', 'Token can be frozen', 30);
    penalize('non_transferable', 'Token may be non-transferable', 50);
    penalize('transfer_hook', 'Transfer hook enabled', 25);
    penalize('balance_mutable_authority', 'Mutable balance authority detected', 40);
    penalize('transfer_fee', 'Transfer fee enabled', 15);
    penalize('transfer_fee_upgradable', 'Transfer fee can be upgraded', 20);
    penalize('transfer_hook_upgradable', 'Transfer hook can be upgraded', 20);
    penalize('closable', 'Token account/program is closable', 15);
    penalize('metadata_mutable', 'Metadata is mutable', 5);
    score = Math.max(0, score);
    const critical = ['mintable', 'freezable', 'non_transferable', 'balance_mutable_authority']
      .some((field) => status(field) === '1');

    return {
      available: true,
      passed: !critical && score >= 60,
      score,
      risks,
      checks: {},
    };
  }

  public async ethereum(address: string, signal?: AbortSignal): Promise<ScannerSecurityResult> {
    const normalized = address.toLowerCase();
    const url = new URL('/api/v1/token_security/1', this.baseUrl);
    url.searchParams.set('contract_addresses', normalized);
    const payload = objectValue(await this.request(url, signal));

    if (payload?.['code'] !== 1) {
      throw new ScannerProviderError('PROVIDER_RESPONSE_MALFORMED', false, null, 'goplus');
    }

    const result = objectValue(payload['result']);
    const entry = result === undefined
      ? undefined
      : Object.entries(result).find(([key]) => key.toLowerCase() === normalized)?.[1];
    const data = objectValue(entry);

    if (data === undefined) {
      return unavailable();
    }

    const checks: Record<string, string> = {};
    const risks: string[] = [];

    for (const [field, safe] of Object.entries(ethereumFlags)) {
      const value = data[field];

      if (value !== '0' && value !== '1') {
        return unavailable();
      }

      checks[field] = value;

      if (value !== safe) {
        risks.push(field.toUpperCase());
      }
    }

    return {
      available: true,
      passed: risks.length === 0,
      score: risks.length === 0 ? 100 : 0,
      risks,
      checks,
    };
  }

  private request(url: URL, signal?: AbortSignal): Promise<unknown> {
    return this.client.json(url, {
      headers: this.accessToken === undefined || this.accessToken === ''
        ? {}
        : { authorization: `Bearer ${this.accessToken}` },
      timeoutMs: this.timeoutMs,
      provider: 'goplus',
      ...(signal === undefined ? {} : { signal }),
    });
  }
}

function unavailable(): ScannerSecurityResult {
  return { available: false, passed: null, score: null, risks: [], checks: {} };
}
