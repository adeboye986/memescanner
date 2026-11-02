import { ScannerProviderError } from './scanner-http-client.js';

export const BIRDEYE_MINIMUM_REQUEST_INTERVAL_MS = 1_200;
export const BIRDEYE_MAXIMUM_REQUEST_ATTEMPTS = 3;
export const BIRDEYE_RETRY_BASE_DELAY_MS = 5_000;
export const BIRDEYE_MAXIMUM_RETRY_DELAY_MS = 30_000;
export const BIRDEYE_MAXIMUM_RETRY_AFTER_MS = 30_000;
export const BIRDEYE_MAXIMUM_JITTER_MS = 1_000;

type Sleep = (milliseconds: number, signal?: AbortSignal) => Promise<void>;

export interface BirdeyeRequestGateOptions {
  readonly minimumRequestIntervalMs?: number;
  readonly maximumRequestAttempts?: number;
  readonly retryBaseDelayMs?: number;
  readonly maximumRetryDelayMs?: number;
  readonly maximumRetryAfterMs?: number;
  readonly maximumJitterMs?: number;
  readonly now?: () => number;
  readonly random?: () => number;
  readonly sleep?: Sleep;
}

export class BirdeyeRequestGate {
  private readonly minimumRequestIntervalMs: number;
  private readonly maximumRequestAttempts: number;
  private readonly retryBaseDelayMs: number;
  private readonly maximumRetryDelayMs: number;
  private readonly maximumRetryAfterMs: number;
  private readonly maximumJitterMs: number;
  private readonly now: () => number;
  private readonly random: () => number;
  private readonly sleep: Sleep;
  private queue: Promise<void> = Promise.resolve();
  private nextRequestAt = 0;

  public constructor(options: BirdeyeRequestGateOptions = {}) {
    this.minimumRequestIntervalMs = nonNegativeInteger(
      options.minimumRequestIntervalMs,
      BIRDEYE_MINIMUM_REQUEST_INTERVAL_MS,
    );
    this.maximumRequestAttempts = positiveInteger(
      options.maximumRequestAttempts,
      BIRDEYE_MAXIMUM_REQUEST_ATTEMPTS,
    );
    this.retryBaseDelayMs = nonNegativeInteger(options.retryBaseDelayMs, BIRDEYE_RETRY_BASE_DELAY_MS);
    this.maximumRetryDelayMs = nonNegativeInteger(
      options.maximumRetryDelayMs,
      BIRDEYE_MAXIMUM_RETRY_DELAY_MS,
    );
    this.maximumRetryAfterMs = nonNegativeInteger(
      options.maximumRetryAfterMs,
      BIRDEYE_MAXIMUM_RETRY_AFTER_MS,
    );
    this.maximumJitterMs = nonNegativeInteger(options.maximumJitterMs, BIRDEYE_MAXIMUM_JITTER_MS);
    this.now = options.now ?? Date.now;
    this.random = options.random ?? Math.random;
    this.sleep = options.sleep ?? sleep;
  }

  public async executeGet<T>(operation: () => Promise<T>, signal?: AbortSignal): Promise<T> {
    const previousRequest = this.queue;
    let releaseRequest!: () => void;
    this.queue = new Promise<void>((resolve) => {
      releaseRequest = resolve;
    });

    await previousRequest;

    try {
      return await this.executeAttempts(operation, signal);
    } finally {
      releaseRequest();
    }
  }

  private async executeAttempts<T>(operation: () => Promise<T>, signal?: AbortSignal): Promise<T> {
    for (let attempt = 1; attempt <= this.maximumRequestAttempts; attempt += 1) {
      await this.waitForRequestWindow(signal);
      this.throwIfAborted(signal);

      const requestStartedAt = this.now();
      this.nextRequestAt = Math.max(
        this.nextRequestAt,
        requestStartedAt + this.minimumRequestIntervalMs,
      );

      try {
        return await operation();
      } catch (error) {
        if (!(error instanceof ScannerProviderError)
          || !error.retryable
          || signal?.aborted === true) {
          throw error;
        }

        const retryDelayMs = this.retryDelayMs(error, attempt);
        this.nextRequestAt = Math.max(this.nextRequestAt, this.now() + retryDelayMs);

        if (attempt === this.maximumRequestAttempts) {
          throw error;
        }
      }
    }

    throw new ScannerProviderError('PROVIDER_SERVICE_FAILED', true, null, 'birdeye');
  }

  private async waitForRequestWindow(signal?: AbortSignal): Promise<void> {
    let delayMs = this.nextRequestAt - this.now();

    while (delayMs > 0) {
      this.throwIfAborted(signal);
      await this.sleep(delayMs, signal);
      delayMs = this.nextRequestAt - this.now();
    }
  }

  private retryDelayMs(error: ScannerProviderError, attempt: number): number {
    if (error.code === 'PROVIDER_RATE_LIMITED' && error.retryAfterMs !== null) {
      return Math.min(error.retryAfterMs, this.maximumRetryAfterMs);
    }

    const exponentialDelayMs = Math.min(
      this.retryBaseDelayMs * (2 ** (attempt - 1)),
      this.maximumRetryDelayMs,
    );
    const jitterMs = Math.floor(Math.max(0, Math.min(1, this.random())) * this.maximumJitterMs);

    return Math.min(exponentialDelayMs + jitterMs, this.maximumRetryDelayMs);
  }

  private throwIfAborted(signal?: AbortSignal): void {
    if (signal?.aborted === true) {
      throw new ScannerProviderError('PROVIDER_REQUEST_ABORTED', true, null, 'birdeye');
    }
  }
}

function nonNegativeInteger(value: number | undefined, fallback: number): number {
  return value !== undefined && Number.isSafeInteger(value) && value >= 0
    ? value
    : fallback;
}

function positiveInteger(value: number | undefined, fallback: number): number {
  return value !== undefined && Number.isSafeInteger(value) && value > 0
    ? value
    : fallback;
}

async function sleep(milliseconds: number, signal?: AbortSignal): Promise<void> {
  if (milliseconds <= 0) {
    return;
  }

  await new Promise<void>((resolve, reject) => {
    if (signal?.aborted === true) {
      reject(new ScannerProviderError('PROVIDER_REQUEST_ABORTED', true, null, 'birdeye'));
      return;
    }

    const removeAbortListener = (): void => {
      signal?.removeEventListener('abort', abort);
    };
    const complete = (): void => {
      removeAbortListener();
      resolve();
    };
    const abort = (): void => {
      clearTimeout(timer);
      removeAbortListener();
      reject(new ScannerProviderError('PROVIDER_REQUEST_ABORTED', true, null, 'birdeye'));
    };
    const timer = setTimeout(complete, milliseconds);
    signal?.addEventListener('abort', abort, { once: true });
  });
}
