import type { ScannerHttpClient } from './scanner-http-client.js';
import {
  ScannerProviderError,
  arrayValue,
  finiteNumber,
  objectValue,
  stringValue,
} from './scanner-http-client.js';

export interface HolderRisk {
  readonly available: boolean;
  readonly passed: boolean | null;
  readonly score: number | null;
  readonly level: string;
  readonly reasons: readonly string[];
  readonly largestHolderPercentage: number | null;
  readonly top5Percentage: number | null;
  readonly top10Percentage: number | null;
}

const systemProgram = '11111111111111111111111111111111';
const pumpFunProgram = '6EF8rrecthR5Dkzon8Nwu78hRvfCKubJ14M5uBEwF6P';

export class SolanaRpcScannerProvider {
  private requestId = 0;

  public constructor(
    private readonly client: ScannerHttpClient,
    private readonly rpcUrl: URL,
    private readonly timeoutMs: number,
  ) {}

  public async holderRisk(mint: string, signal?: AbortSignal): Promise<HolderRisk> {
    const timeout = AbortSignal.timeout(this.timeoutMs);
    const operationSignal = signal === undefined ? timeout : AbortSignal.any([timeout, signal]);
    const [largestResult, supplyResult] = await Promise.all([
      this.rpc('getTokenLargestAccounts', [mint, { commitment: 'confirmed' }], operationSignal),
      this.rpc('getTokenSupply', [mint, { commitment: 'confirmed' }], operationSignal),
    ]);
    const accounts = arrayValue(objectValue(largestResult)?.['value']);
    const supply = objectValue(objectValue(supplyResult)?.['value']);
    const totalSupply = finiteNumber(supply?.['uiAmountString'] ?? supply?.['uiAmount']);

    if (accounts === undefined || totalSupply === undefined || totalSupply <= 0) {
      throw new ScannerProviderError('PROVIDER_RESPONSE_MALFORMED', false, null, 'solana_rpc');
    }

    const balances = new Map<string, number>();

    for (const raw of accounts) {
      const account = objectValue(raw);
      const tokenAccount = stringValue(account?.['address'], 128);
      const amount = finiteNumber(account?.['uiAmountString'] ?? account?.['uiAmount']);

      if (tokenAccount === undefined || amount === undefined || amount < 0) {
        continue;
      }

      const tokenInfo = objectValue(await this.rpc(
        'getAccountInfo',
        [tokenAccount, { encoding: 'jsonParsed', commitment: 'confirmed' }],
        operationSignal,
      ));
      const owner = stringValue(
        objectValue(objectValue(objectValue(tokenInfo?.['value'])?.['data'])?.['parsed'])?.['info'] === undefined
          ? undefined
          : objectValue(objectValue(objectValue(objectValue(tokenInfo?.['value'])?.['data'])?.['parsed'])?.['info'])?.['owner'],
        128,
      );

      if (owner === undefined) {
        continue;
      }

      const ownerResult = objectValue(await this.rpc(
        'getAccountInfo',
        [owner, { encoding: 'jsonParsed', commitment: 'confirmed' }],
        operationSignal,
      ));
      const ownerInfo = objectValue(ownerResult?.['value']);
      const program = stringValue(ownerInfo?.['owner'], 128);
      const executable = ownerInfo?.['executable'] === true;

      if (executable || program === pumpFunProgram || program !== systemProgram) {
        continue;
      }

      balances.set(owner, (balances.get(owner) ?? 0) + amount);
    }

    const percentages = [...balances.values()]
      .map((amount) => (amount / totalSupply) * 100)
      .sort((left, right) => right - left);

    return evaluateHolderRisk(
      percentages[0] ?? 0,
      percentages.slice(0, 5).reduce((sum, value) => sum + value, 0),
      percentages.slice(0, 10).reduce((sum, value) => sum + value, 0),
    );
  }

  private async rpc(method: string, params: readonly unknown[], signal?: AbortSignal): Promise<unknown> {
    this.requestId += 1;
    const payload = objectValue(await this.client.json(this.rpcUrl, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ jsonrpc: '2.0', id: this.requestId, method, params }),
      timeoutMs: this.timeoutMs,
      provider: 'solana_rpc',
      ...(signal === undefined ? {} : { signal }),
    }));

    if (payload === undefined || payload['error'] !== undefined) {
      throw new ScannerProviderError('PROVIDER_RESPONSE_MALFORMED', false, null, 'solana_rpc');
    }

    return payload['result'];
  }
}

export function evaluateHolderRisk(largest: number, top5: number, top10: number): HolderRisk {
  let score = 100;
  const reasons: string[] = [];
  let level = 'low';
  let passed = true;

  if (largest > 10) {
    score -= 60;
    level = 'critical';
    passed = false;
    reasons.push('Largest holder controls more than 10%.');
  } else if (largest > 5) {
    score -= 35;
    level = 'high';
    reasons.push('Largest holder controls more than 5%.');
  } else if (largest > 3.5) {
    score -= 15;
    level = 'warning';
    reasons.push('Largest holder exceeds 3.5%.');
  }

  if (top5 > 30) {
    score -= 25;
    level = level === 'critical' ? level : 'high';
    reasons.push('Top 5 holders control more than 30%.');
  } else if (top5 > 20) {
    score -= 10;
    level = level === 'low' ? 'warning' : level;
    reasons.push('Top 5 holders control more than 20%.');
  }

  if (top10 > 50) {
    score -= 25;
    level = level === 'critical' ? level : 'high';
    reasons.push('Top 10 holders control more than 50%.');
  } else if (top10 > 35) {
    score -= 10;
    level = level === 'low' ? 'warning' : level;
    reasons.push('Top 10 holders control more than 35%.');
  }

  if (largest > 10 || top5 > 50 || top10 > 70) {
    passed = false;
  }

  return {
    available: true,
    passed,
    score: Math.max(0, Math.min(score, 100)),
    level,
    reasons,
    largestHolderPercentage: round4(largest),
    top5Percentage: round4(top5),
    top10Percentage: round4(top10),
  };
}

function round4(value: number): number {
  return Math.round(value * 10_000) / 10_000;
}
