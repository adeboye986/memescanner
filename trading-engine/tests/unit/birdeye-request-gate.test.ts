import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
  BirdeyeScannerProvider,
  type BirdeyeListing,
} from '../../src/infrastructure/scanning/birdeye-scanner-provider.js';
import type { BirdeyeRequestGateOptions } from '../../src/infrastructure/scanning/birdeye-request-gate.js';
import {
  ScannerHttpClient,
  type ScannerProviderError,
} from '../../src/infrastructure/scanning/scanner-http-client.js';

const clockStart = new Date('2026-10-28T12:00:00.000Z');

describe('Birdeye shared request gate', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(clockStart);
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('paces discovery and overview through one provider-wide request timeline', async () => {
    const requestStarts: number[] = [];
    const provider = providerUsing((url) => {
      requestStarts.push(Date.now());

      return url.pathname === '/defi/v2/tokens/new_listing'
        ? listingResponse()
        : overviewResponse();
    });

    await expect(provider.newListings()).resolves.toHaveLength(1);
    const overview = provider.overview('mint-address');

    await vi.advanceTimersByTimeAsync(1_199);
    expect(requestStarts).toEqual([clockStart.getTime()]);

    await vi.advanceTimersByTimeAsync(1);
    await expect(overview).resolves.toMatchObject({ address: 'mint-address' });
    expect(requestStarts).toEqual([
      clockStart.getTime(),
      clockStart.getTime() + 1_200,
    ]);
  });

  it('serializes concurrent requests without allowing them to bypass pacing', async () => {
    const requestStarts: number[] = [];
    const provider = providerUsing((url) => {
      requestStarts.push(Date.now());

      return url.pathname === '/defi/v2/tokens/new_listing'
        ? listingResponse()
        : overviewResponse();
    });
    const requests = [
      provider.newListings(),
      provider.overview('mint-one'),
      provider.overview('mint-two'),
    ];

    await vi.advanceTimersByTimeAsync(2_400);
    await expect(Promise.all(requests)).resolves.toHaveLength(3);
    expect(requestStarts).toEqual([
      clockStart.getTime(),
      clockStart.getTime() + 1_200,
      clockStart.getTime() + 2_400,
    ]);
  });

  it('recovers from a transient 429 with bounded exponential backoff and controlled jitter', async () => {
    const requestStarts: number[] = [];
    let attempts = 0;
    const provider = providerUsing(() => {
      requestStarts.push(Date.now());
      attempts += 1;

      return attempts === 1
        ? new Response('', { status: 429 })
        : listingResponse();
    }, {
      random: () => 0.5,
    });
    const request = provider.newListings();

    await vi.advanceTimersByTimeAsync(5_499);
    expect(attempts).toBe(1);

    await vi.advanceTimersByTimeAsync(1);
    await expect(request).resolves.toHaveLength(1);
    expect(requestStarts).toEqual([
      clockStart.getTime(),
      clockStart.getTime() + 5_500,
    ]);
  });

  it('honors Retry-After and bounds an excessive provider delay', async () => {
    const requestStarts: number[] = [];
    let attempts = 0;
    const provider = providerUsing(() => {
      requestStarts.push(Date.now());
      attempts += 1;

      if (attempts === 1) {
        return new Response('', {
          status: 429,
          headers: { 'retry-after': new Date(clockStart.getTime() + 2_000).toUTCString() },
        });
      }

      if (attempts === 2) {
        return new Response('', { status: 429, headers: { 'retry-after': '120' } });
      }

      return listingResponse();
    });
    const request = provider.newListings();

    await vi.advanceTimersByTimeAsync(32_000);
    await expect(request).resolves.toHaveLength(1);
    expect(requestStarts).toEqual([
      clockStart.getTime(),
      clockStart.getTime() + 2_000,
      clockStart.getTime() + 32_000,
    ]);
  });

  it('keeps the shared cooldown after persistent 429 exhaustion', async () => {
    const requestStarts: number[] = [];
    let attempts = 0;
    const provider = providerUsing(() => {
      requestStarts.push(Date.now());
      attempts += 1;

      return attempts <= 3
        ? new Response('', { status: 429 })
        : overviewResponse();
    }, {
      retryBaseDelayMs: 1_000,
      maximumJitterMs: 0,
    });
    const exhausted = expect(provider.newListings()).rejects.toMatchObject<Partial<ScannerProviderError>>({
      code: 'PROVIDER_RATE_LIMITED',
      retryable: true,
      statusCode: 429,
    });
    const queued = provider.overview('after-cooldown');

    await vi.advanceTimersByTimeAsync(3_200);
    await exhausted;
    expect(attempts).toBe(3);

    await vi.advanceTimersByTimeAsync(3_999);
    expect(attempts).toBe(3);

    await vi.advanceTimersByTimeAsync(1);
    await expect(queued).resolves.toMatchObject({ address: 'after-cooldown' });
    expect(requestStarts).toEqual([
      clockStart.getTime(),
      clockStart.getTime() + 1_200,
      clockStart.getTime() + 3_200,
      clockStart.getTime() + 7_200,
    ]);
  });

  it('does not retry a non-retryable Birdeye error', async () => {
    let attempts = 0;
    const provider = providerUsing(() => {
      attempts += 1;

      return new Response('', { status: 401 });
    });

    await expect(provider.newListings()).rejects.toMatchObject<Partial<ScannerProviderError>>({
      code: 'PROVIDER_AUTHENTICATION_FAILED',
      retryable: false,
      statusCode: 401,
    });
    expect(attempts).toBe(1);
  });
});

function providerUsing(
  handler: (url: URL) => Response | Promise<Response>,
  requestGateOptions: BirdeyeRequestGateOptions = {},
): BirdeyeScannerProvider {
  const fetchImplementation: typeof fetch = async (input) => {
    const url = input instanceof URL
      ? input
      : input instanceof Request
        ? new URL(input.url)
        : new URL(input);

    return await handler(url);
  };

  return new BirdeyeScannerProvider(
    new ScannerHttpClient(fetchImplementation),
    new URL('https://public-api.birdeye.so'),
    'test-key',
    1_000,
    requestGateOptions,
  );
}

function listingResponse(listings: readonly BirdeyeListing[] = [{
  address: 'mint-address',
  symbol: 'TOK',
  name: 'Token',
  liquidity: 6_000,
}]): Response {
  return Response.json({ data: { items: listings } });
}

function overviewResponse(): Response {
  return Response.json({
    data: {
      symbol: 'TOK',
      name: 'Token',
      price: 0.001,
      marketCap: 10_000,
      liquidity: 6_000,
      holder: 25,
      v1m: 900,
      buy1m: 12,
      sell1m: 4,
      uniqueWallet5m: 17,
      priceChange5mPercent: 8.5,
    },
  });
}
