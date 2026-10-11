import { request as httpsRequest } from 'node:https';

import {
  canonicalizeDatabaseDecimal,
  compareCanonicalDecimals,
} from '../../shared/amount/canonical-decimal.js';

export const DEXSCREENER_MAX_TOKEN_ADDRESSES = 30;
export const DEXSCREENER_MAX_RESPONSE_BYTES = 4 * 1024 * 1024;
/** Retry-After hints are advisory and capped at one hour before persistence. */
export const DEXSCREENER_MAX_RETRY_AFTER_MS = 60 * 60 * 1_000;
const maximumDatabaseIntegerDigits = 48;
const maximumDatabaseFractionDigits = 30;
const maximumPairAddressLength = 128;
const maximumDexIdentifierLength = 64;
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
      readonly signal?: AbortSignal;
    },
  ): Promise<PaperMarketHttpResponse>;
}

export type PaperMarketTransportErrorCode =
  | 'ABORTED'
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
      readonly signal?: AbortSignal;
    },
  ): Promise<PaperMarketHttpResponse> {
    if (url.protocol !== 'https:') {
      return Promise.reject(new PaperMarketTransportError('NETWORK'));
    }

    if (options.signal?.aborted === true) {
      return Promise.reject(new PaperMarketTransportError('ABORTED'));
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
        signal: options.signal,
        headers: {
          accept: 'application/json',
          'user-agent': 'meme-scanner-trading-engine/0.1',
        },
      }, (response) => {
        const chunks: Buffer[] = [];
        let bytes = 0;
        const contentLength = headerValue(response.headers['content-length']);

        if (contentLength !== undefined
          && /^\d+$/.test(contentLength)
          && Number(contentLength) > DEXSCREENER_MAX_RESPONSE_BYTES) {
          finish(new PaperMarketTransportError('RESPONSE_TOO_LARGE'));
          response.destroy();
          request.destroy();

          return;
        }

        response.on('data', (chunk: Buffer | string): void => {
          const buffer = Buffer.isBuffer(chunk) ? chunk : Buffer.from(chunk);
          bytes += buffer.byteLength;

          if (bytes > DEXSCREENER_MAX_RESPONSE_BYTES) {
            finish(new PaperMarketTransportError('RESPONSE_TOO_LARGE'));
            response.destroy();
            request.destroy();
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
        response.once('aborted', (): void => {
          finish(new PaperMarketTransportError('NETWORK'));
        });
        response.once('error', (): void => {
          finish(new PaperMarketTransportError('NETWORK'));
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
        finish(options.signal?.aborted === true
          ? new PaperMarketTransportError('ABORTED')
          : error instanceof PaperMarketTransportError
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
      | 'PROVIDER_REQUEST_ABORTED'
      | 'PROVIDER_RESPONSE_TOO_LARGE'
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
  readonly fetchSolana: (
    addresses: readonly string[],
    signal?: AbortSignal,
  ) => Promise<readonly PaperMarketFetchResult[]>;
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
    signal?: AbortSignal,
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
        ...(signal === undefined ? {} : { signal }),
      });
    } catch (error) {
      if (error instanceof PaperMarketTransportError && error.code === 'TIMEOUT') {
        throw new PaperMarketProviderError('PROVIDER_TIMEOUT', true);
      }

      if (error instanceof PaperMarketTransportError && error.code === 'RESPONSE_TOO_LARGE') {
        throw new PaperMarketProviderError('PROVIDER_RESPONSE_TOO_LARGE', false);
      }

      if (error instanceof PaperMarketTransportError && error.code === 'ABORTED') {
        throw new PaperMarketProviderError('PROVIDER_REQUEST_ABORTED', true);
      }

      throw new PaperMarketProviderError('PROVIDER_NETWORK_ERROR', true);
    }

    const status = normalizedHttpStatus(response.status);

    if (status === null || !Number.isFinite(response.retrievedAt.getTime())) {
      throw new PaperMarketProviderError('PROVIDER_HTTP_ERROR', false);
    }

    if (status === 429) {
      throw new PaperMarketProviderError(
        'PROVIDER_RATE_LIMITED',
        true,
        429,
        retryAfterMilliseconds(response.headers['retry-after'], this.now()),
      );
    }

    if (status < 200 || status >= 300) {
      throw new PaperMarketProviderError(
        'PROVIDER_HTTP_ERROR',
        status >= 500,
        status,
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
  if (Buffer.byteLength(body, 'utf8') > DEXSCREENER_MAX_RESPONSE_BYTES) {
    throw new PaperMarketProviderError('PROVIDER_RESPONSE_TOO_LARGE', false);
  }

  let payload: unknown;

  try {
    payload = JSON.parse(body, preserveJsonNumberSource) as unknown;
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
    const rawLiquidity = pair['liquidity'];
    const liquidity = objectValue(rawLiquidity);
    const liquidityUsd = liquidity === undefined
      ? null
      : decimalValue(liquidity['usd'], false);
    const pairAddress = boundedStringValue(pair['pairAddress'], maximumPairAddressLength);
    const dex = boundedStringValue(pair['dexId'], maximumDexIdentifierLength);

    if (priceUsd === null || marketCapUsd === null
      || (rawLiquidity !== undefined && rawLiquidity !== null && liquidity === undefined)
      || (liquidity?.['usd'] !== undefined && liquidityUsd === null)
      || !pairAddress.valid || !dex.valid) {
      return [];
    }

    return [{
      pairAddress: pairAddress.value,
      dex: dex.value,
      priceUsd,
      marketCapUsd,
      liquidityUsd,
    }];
  });
  eligible.sort(compareEligiblePairs);
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
      pairAddress: selected.pairAddress,
      dex: selected.dex,
      marketCapUsd: selected.marketCapUsd,
      priceUsd: selected.priceUsd,
      liquidityUsd: selected.liquidityUsd,
      fetchedAt,
      providerObservedAt: null,
    },
  };
}

function decimalValue(value: unknown, requirePositive: boolean): string | null {
  if (typeof value !== 'string') {
    return null;
  }

  const raw = value.trim();
  const match = /^([0-9]+)(?:\.([0-9]+))?$/.exec(raw);

  if (match === null) {
    return null;
  }

  const integerDigits = (match[1] ?? '').replace(/^0+(?=[0-9])/, '');
  const fractionalDigits = match[2] ?? '';

  if (integerDigits.length > maximumDatabaseIntegerDigits
    || fractionalDigits.length > maximumDatabaseFractionDigits) {
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

interface BoundedString {
  readonly valid: boolean;
  readonly value: string | null;
}

function boundedStringValue(value: unknown, maximumLength: number): BoundedString {
  if (value === undefined || value === null) {
    return { valid: true, value: null };
  }

  if (typeof value !== 'string') {
    return { valid: false, value: null };
  }

  const normalized = value.trim();

  return normalized !== ''
    && normalized.length <= maximumLength
    && hasOnlyPersistableCharacters(normalized)
    ? { valid: true, value: normalized }
    : { valid: false, value: null };
}

function hasOnlyPersistableCharacters(value: string): boolean {
  for (let index = 0; index < value.length; index += 1) {
    const codeUnit = value.charCodeAt(index);

    if (codeUnit < 32 || codeUnit === 127) {
      return false;
    }
  }

  return true;
}

function headerValue(value: string | readonly string[] | undefined): string | undefined {
  return typeof value === 'string' ? value : value?.[0];
}

function retryAfterMilliseconds(value: string | undefined, now: Date): number | null {
  if (value === undefined) {
    return null;
  }

  const normalized = value.trim();

  if (/^\d+$/.test(normalized)) {
    const maximumSeconds = DEXSCREENER_MAX_RETRY_AFTER_MS / 1_000;

    if (normalized.length > maximumSeconds.toString().length) {
      return DEXSCREENER_MAX_RETRY_AFTER_MS;
    }

    return Math.min(Number(normalized) * 1_000, DEXSCREENER_MAX_RETRY_AFTER_MS);
  }

  if (/^[+-]?\d/.test(normalized)) {
    return null;
  }

  const timestamp = Date.parse(normalized);

  return Number.isFinite(timestamp)
    ? Math.min(
      Math.max(0, timestamp - now.getTime()),
      DEXSCREENER_MAX_RETRY_AFTER_MS,
    )
    : null;
}

function normalizedHttpStatus(status: number): number | null {
  return Number.isInteger(status) && status >= 100 && status <= 599
    ? status
    : null;
}

function preserveJsonNumberSource(
  _key: string,
  value: unknown,
  context?: { readonly source?: string },
): unknown {
  if (typeof value !== 'number') {
    return value;
  }

  return context?.source;
}

interface EligiblePair {
  readonly pairAddress: string | null;
  readonly dex: string | null;
  readonly priceUsd: string;
  readonly marketCapUsd: string;
  readonly liquidityUsd: string | null;
}

function compareEligiblePairs(left: EligiblePair, right: EligiblePair): number {
  const liquidity = compareCanonicalDecimals(
    right.liquidityUsd ?? '0',
    left.liquidityUsd ?? '0',
  );

  if (liquidity !== 0) {
    return liquidity;
  }

  const leftKey = stablePairKey(left);
  const rightKey = stablePairKey(right);

  return leftKey < rightKey ? -1 : leftKey > rightKey ? 1 : 0;
}

function stablePairKey(pair: EligiblePair): string {
  return [
    pair.pairAddress ?? '',
    pair.dex ?? '',
    pair.priceUsd,
    pair.marketCapUsd,
  ].join('\u0000');
}
