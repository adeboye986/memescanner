import { request as httpsRequest } from 'node:https';

import {
  canonicalizeDatabaseDecimal,
  compareCanonicalDecimals,
} from '../../shared/amount/canonical-decimal.js';

export const DEXSCREENER_MAX_TOKEN_ADDRESSES = 30;
const maximumResponseBytes = 4 * 1024 * 1024;
const solanaAddress = /^[1-9A-HJ-NP-Za-km-z]{32,44}$/;

export interface PaperMarketHttpResponse {
  readonly status: number;
  readonly body: string;
  readonly headers: Readonly<Record<string, string | undefined>>;
  readonly retrievedAt: Date;
}

export interface PaperMarketHttpTransport {
  get(
    url: URL,
    options: {
      readonly connectionTimeoutMs: number;
      readonly totalTimeoutMs: number;
    },
  ): Promise<PaperMarketHttpResponse>;
}

export type PaperMarketTransportErrorCode =
  | 'NETWORK'
  | 'RESPONSE_TOO_LARGE'
  | 'TIMEOUT';

export class PaperMarketTransportError extends Error {
  public constructor(public readonly code: PaperMarketTransportErrorCode) {
    super('PAPER market provider transport failed');
    this.name = 'PaperMarketTransportError';
  }
}

export class NodeHttpsPaperMarketTransport implements PaperMarketHttpTransport {
  public get(
    url: URL,
    options: {
      readonly connectionTimeoutMs: number;
      readonly totalTimeoutMs: number;
    },
  ): Promise<PaperMarketHttpResponse> {
    if (url.protocol !== 'https:') {
      return Promise.reject(new PaperMarketTransportError('NETWORK'));
    }

    return new Promise((resolve, reject) => {
      const timers: {
        connection: NodeJS.Timeout | undefined;
        total: NodeJS.Timeout | undefined;
      } = { connection: undefined, total: undefined };
      let settled = false;
      const finish = (
        result: PaperMarketHttpResponse | PaperMarketTransportError,
      ): void => {
        if (settled) {
          return;
        }

        settled = true;
        if (timers.connection !== undefined) {
          clearTimeout(timers.connection);
        }
        if (timers.total !== undefined) {
          clearTimeout(timers.total);
        }

        if (result instanceof PaperMarketTransportError) {
          reject(result);
        } else {
          resolve(result);
        }
      };
      const request = httpsRequest(url, {
        method: 'GET',
        headers: {
          accept: 'application/json',
          'user-agent': 'meme-scanner-trading-engine/0.1',
        },
      }, (response) => {
        const chunks: Buffer[] = [];
        let bytes = 0;

        response.on('data', (chunk: Buffer | string): void => {
          const buffer = Buffer.isBuffer(chunk) ? chunk : Buffer.from(chunk);
          bytes += buffer.byteLength;

          if (bytes > maximumResponseBytes) {
            request.destroy(new PaperMarketTransportError('RESPONSE_TOO_LARGE'));
            return;
          }

          chunks.push(buffer);
        });
        response.on('end', (): void => {
          finish({
            status: response.statusCode ?? 0,
            body: Buffer.concat(chunks).toString('utf8'),
            headers: {
              'retry-after': headerValue(response.headers['retry-after']),
            },
            retrievedAt: new Date(),
          });
        });
      });

      timers.total = setTimeout(() => {
        request.destroy(new PaperMarketTransportError('TIMEOUT'));
      }, options.totalTimeoutMs);
      request.once('socket', (socket): void => {
        if (!socket.connecting) {
          return;
        }

        timers.connection = setTimeout(() => {
          request.destroy(new PaperMarketTransportError('TIMEOUT'));
        }, options.connectionTimeoutMs);
        const connected = (): void => {
          if (timers.connection !== undefined) {
            clearTimeout(timers.connection);
            timers.connection = undefined;
          }
        };
        socket.once('connect', connected);
        socket.once('secureConnect', connected);
      });
      request.once('error', (error: Error): void => {
        finish(error instanceof PaperMarketTransportError
          ? error
          : new PaperMarketTransportError('NETWORK'));
      });
      request.end();
    });
  }
}

export interface DexScreenerPaperMarketProviderOptions {
  readonly connectionTimeoutMs: number;
  readonly totalTimeoutMs: number;
  readonly baseUrl?: URL;
}

export interface NormalizedPaperMarketObservation {
  readonly networkId: 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp';
  readonly assetAddress: string;
  readonly provider: 'dexscreener';
  readonly pairAddress: string | null;
  readonly dex: string | null;
  readonly marketCapUsd: string;
  readonly priceUsd: string;
  readonly liquidityUsd: string | null;
  readonly fetchedAt: Date;
  readonly providerObservedAt: null;
}

export type PaperMarketFetchResult =
  | {
      readonly available: true;
      readonly observation: NormalizedPaperMarketObservation;
    }
  | {
      readonly available: false;
      readonly assetAddress: string;
      readonly errorCode: 'PROVIDER_MARKET_INVALID' | 'PROVIDER_PAIR_NOT_FOUND';
    };

export class PaperMarketProviderError extends Error {
  public constructor(
    public readonly errorCode:
      | 'PROVIDER_HTTP_ERROR'
      | 'PROVIDER_NETWORK_ERROR'
      | 'PROVIDER_RATE_LIMITED'
      | 'PROVIDER_RESPONSE_MALFORMED'
      | 'PROVIDER_TIMEOUT',
    public readonly retryable: boolean,
    public readonly statusCode: number | null = null,
    public readonly retryAfterMs: number | null = null,
  ) {
    super('PAPER market provider request failed');
    this.name = 'PaperMarketProviderError';
  }
}

export interface PaperMarketDataProvider {
  readonly fetchSolana: (addresses: readonly string[]) => Promise<readonly PaperMarketFetchResult[]>;
}

export class DexScreenerPaperMarketProvider implements PaperMarketDataProvider {
  private readonly baseUrl: URL;

  public constructor(
    private readonly transport: PaperMarketHttpTransport,
    private readonly options: DexScreenerPaperMarketProviderOptions,
    private readonly now: () => Date = () => new Date(),
  ) {
    this.baseUrl = options.baseUrl ?? new URL('https://api.dexscreener.com');
  }

  public async fetchSolana(
    addresses: readonly string[],
  ): Promise<readonly PaperMarketFetchResult[]> {
    const unique = [...new Set(addresses)];

    if (unique.length === 0 || unique.length > DEXSCREENER_MAX_TOKEN_ADDRESSES
      || unique.some((address) => !solanaAddress.test(address))) {
      throw new PaperMarketProviderError(
        'PROVIDER_RESPONSE_MALFORMED',
        false,
      );
    }

    const path = unique.map((address) => encodeURIComponent(address)).join(',');
    const url = new URL(`/tokens/v1/solana/${path}`, this.baseUrl);
    let response: PaperMarketHttpResponse;

    try {
      response = await this.transport.get(url, {
        connectionTimeoutMs: this.options.connectionTimeoutMs,
        totalTimeoutMs: this.options.totalTimeoutMs,
      });
    } catch (error) {
      if (error instanceof PaperMarketTransportError && error.code === 'TIMEOUT') {
        throw new PaperMarketProviderError('PROVIDER_TIMEOUT', true);
      }

      throw new PaperMarketProviderError('PROVIDER_NETWORK_ERROR', true);
    }

    if (response.status === 429) {
      throw new PaperMarketProviderError(
        'PROVIDER_RATE_LIMITED',
        true,
        429,
        retryAfterMilliseconds(response.headers['retry-after'], this.now()),
      );
    }

    if (response.status < 200 || response.status >= 300) {
      throw new PaperMarketProviderError(
        'PROVIDER_HTTP_ERROR',
        response.status >= 500,
        response.status,
      );
    }

    const payload = parsePayload(response.body);

    return unique.map((address) => normalizeAddress(
      address,
      payload,
      response.retrievedAt,
    ));
  }
}

function parsePayload(body: string): readonly Record<string, unknown>[] {
  let payload: unknown;

  try {
    payload = JSON.parse(body) as unknown;
  } catch {
    throw new PaperMarketProviderError('PROVIDER_RESPONSE_MALFORMED', false);
  }

  if (!Array.isArray(payload)
    || payload.some((pair) => typeof pair !== 'object' || pair === null || Array.isArray(pair))) {
    throw new PaperMarketProviderError('PROVIDER_RESPONSE_MALFORMED', false);
  }

  return payload as readonly Record<string, unknown>[];
}

function normalizeAddress(
  address: string,
  pairs: readonly Record<string, unknown>[],
  fetchedAt: Date,
): PaperMarketFetchResult {
  const matching = pairs.filter((pair) => {
    const baseToken = objectValue(pair['baseToken']);

    return baseToken?.['address'] === address
      && (pair['chainId'] === undefined || pair['chainId'] === 'solana');
  });
  const eligible = matching.flatMap((pair) => {
    const priceUsd = decimalValue(pair['priceUsd'], true);
    const marketCapUsd = decimalValue(pair['marketCap'], true);
    const liquidity = objectValue(pair['liquidity']);
    const liquidityUsd = liquidity === undefined
      ? null
      : decimalValue(liquidity['usd'], false);

    if (priceUsd === null || marketCapUsd === null
      || (liquidity?.['usd'] !== undefined && liquidityUsd === null)) {
      return [];
    }

    return [{ pair, priceUsd, marketCapUsd, liquidityUsd }];
  });
  eligible.sort((left, right) => compareCanonicalDecimals(
    right.liquidityUsd ?? '0',
    left.liquidityUsd ?? '0',
  ));
  const selected = eligible[0];

  if (selected === undefined) {
    return {
      available: false,
      assetAddress: address,
      errorCode: matching.length === 0
        ? 'PROVIDER_PAIR_NOT_FOUND'
        : 'PROVIDER_MARKET_INVALID',
    };
  }

  return {
    available: true,
    observation: {
      networkId: 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp',
      assetAddress: address,
      provider: 'dexscreener',
      pairAddress: stringValue(selected.pair['pairAddress']),
      dex: stringValue(selected.pair['dexId']),
      marketCapUsd: selected.marketCapUsd,
      priceUsd: selected.priceUsd,
      liquidityUsd: selected.liquidityUsd,
      fetchedAt,
      providerObservedAt: null,
    },
  };
}

function decimalValue(value: unknown, requirePositive: boolean): string | null {
  if (typeof value !== 'string' && typeof value !== 'number') {
    return null;
  }

  const raw = typeof value === 'number'
    ? Number.isFinite(value) ? value.toString() : ''
    : value.trim();

  if (!/^[0-9]+(?:\.[0-9]+)?$/.test(raw)) {
    return null;
  }

  const canonical = canonicalizeDatabaseDecimal(raw);

  if (requirePositive ? canonical === '0' : false) {
    return null;
  }

  return canonical;
}

function objectValue(value: unknown): Readonly<Record<string, unknown>> | undefined {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
    ? value as Readonly<Record<string, unknown>>
    : undefined;
}

function stringValue(value: unknown): string | null {
  return typeof value === 'string' && value.trim() !== '' ? value : null;
}

function headerValue(value: string | readonly string[] | undefined): string | undefined {
  return typeof value === 'string' ? value : value?.[0];
}

function retryAfterMilliseconds(value: string | undefined, now: Date): number | null {
  if (value === undefined) {
    return null;
  }

  if (/^\d+$/.test(value)) {
    return Number(value) * 1_000;
  }

  const timestamp = Date.parse(value);

  return Number.isFinite(timestamp) ? Math.max(0, timestamp - now.getTime()) : null;
}
