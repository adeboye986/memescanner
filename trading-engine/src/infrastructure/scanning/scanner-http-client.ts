export const SCANNER_MAX_RESPONSE_BYTES = 4 * 1024 * 1024;

export type ScannerProviderName =
  | 'birdeye'
  | 'dexscreener'
  | 'geckoterminal'
  | 'goplus'
  | 'solana_rpc';

export class ScannerProviderError extends Error {
  public constructor(
    public readonly code: string,
    public readonly retryable: boolean,
    public readonly statusCode: number | null = null,
    public readonly provider: ScannerProviderName | null = null,
  ) {
    super('Scanner provider request failed');
    this.name = 'ScannerProviderError';
  }
}

export interface ScannerHttpRequest {
  readonly method?: 'GET' | 'POST';
  readonly headers?: Readonly<Record<string, string>>;
  readonly body?: string;
  readonly timeoutMs: number;
  readonly signal?: AbortSignal;
  readonly provider?: ScannerProviderName;
}

export class ScannerHttpClient {
  public constructor(private readonly fetchImplementation: typeof fetch = fetch) {}

  public async json(url: URL, request: ScannerHttpRequest): Promise<unknown> {
    if (url.protocol !== 'https:') {
      throw new ScannerProviderError('PROVIDER_URL_INVALID', false, null, request.provider ?? null);
    }

    let response: Response;
    const timeout = AbortSignal.timeout(request.timeoutMs);

    try {
      const signal = request.signal === undefined
        ? timeout
        : AbortSignal.any([timeout, request.signal]);
      response = await this.fetchImplementation(url, {
        method: request.method ?? 'GET',
        headers: {
          accept: 'application/json',
          'user-agent': 'meme-scanner-trading-engine/0.1',
          ...request.headers,
        },
        ...(request.body === undefined ? {} : { body: request.body }),
        signal,
      });
    } catch (error) {
      const aborted = request.signal?.aborted === true;
      const timedOut = !aborted && (timeout.aborted || isTimeoutError(error));
      throw new ScannerProviderError(
        aborted
          ? 'PROVIDER_REQUEST_ABORTED'
          : timedOut ? 'PROVIDER_TIMEOUT' : 'PROVIDER_TRANSPORT_FAILED',
        true,
        null,
        request.provider ?? null,
      );
    }

    if (response.status < 200 || response.status >= 300) {
      await response.body?.cancel();
      const authenticationFailure = response.status === 401 || response.status === 403;
      const timeoutFailure = response.status === 408;
      throw new ScannerProviderError(
        authenticationFailure
          ? 'PROVIDER_AUTHENTICATION_FAILED'
          : timeoutFailure
            ? 'PROVIDER_TIMEOUT'
            : response.status === 429
              ? 'PROVIDER_RATE_LIMITED'
              : response.status >= 500 ? 'PROVIDER_SERVICE_FAILED' : 'PROVIDER_HTTP_ERROR',
        !authenticationFailure
          && (timeoutFailure || response.status === 429 || response.status >= 500),
        response.status,
        request.provider ?? null,
      );
    }

    const length = response.headers.get('content-length');

    if (length !== null && /^\d+$/.test(length) && Number(length) > SCANNER_MAX_RESPONSE_BYTES) {
      await response.body?.cancel();
      throw new ScannerProviderError('PROVIDER_RESPONSE_TOO_LARGE', false, response.status, request.provider ?? null);
    }

    const bytes = new Uint8Array(await response.arrayBuffer());

    if (bytes.byteLength > SCANNER_MAX_RESPONSE_BYTES) {
      throw new ScannerProviderError('PROVIDER_RESPONSE_TOO_LARGE', false, response.status, request.provider ?? null);
    }

    try {
      return JSON.parse(new TextDecoder().decode(bytes)) as unknown;
    } catch {
      throw new ScannerProviderError('PROVIDER_RESPONSE_MALFORMED', false, response.status, request.provider ?? null);
    }
  }
}

function isTimeoutError(error: unknown): boolean {
  return error instanceof Error
    && error.name === 'TimeoutError';
}

export function objectValue(value: unknown): Readonly<Record<string, unknown>> | undefined {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
    ? value as Readonly<Record<string, unknown>>
    : undefined;
}

export function arrayValue(value: unknown): readonly unknown[] | undefined {
  return Array.isArray(value) ? value : undefined;
}

export function stringValue(value: unknown, maximum = 255): string | undefined {
  return typeof value === 'string' && value.length > 0 && value.length <= maximum
    ? value
    : undefined;
}

export function finiteNumber(value: unknown): number | undefined {
  const candidate = typeof value === 'number'
    ? value
    : typeof value === 'string' && value.trim() !== ''
      ? Number(value)
      : Number.NaN;

  return Number.isFinite(candidate) ? candidate : undefined;
}
