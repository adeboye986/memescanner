import { describe, expect, it, vi } from 'vitest';

import {
  DEXSCREENER_MAX_RESPONSE_BYTES,
  DEXSCREENER_MAX_RETRY_AFTER_MS,
  DexScreenerPaperMarketProvider,
  PaperMarketProviderError,
  PaperMarketTransportError,
  type PaperMarketHttpResponse,
  type PaperMarketHttpTransport,
} from '../../src/infrastructure/market-data/dexscreener-paper-market-provider.js';

const wrappedSol = 'So11111111111111111111111111111111111111112';
const otherToken = '11111111111111111111111111111111';
const retrievedAt = new Date('2026-10-09T12:00:00.000Z');

class StubTransport implements PaperMarketHttpTransport {
  public readonly get = vi.fn<PaperMarketHttpTransport['get']>();
}

function response(
  body: unknown,
  status = 200,
  headers: Readonly<Record<string, string | undefined>> = {},
): PaperMarketHttpResponse {
  return {
    status,
    body: typeof body === 'string' ? body : JSON.stringify(body),
    headers,
    retrievedAt,
  };
}

function provider(
  transport: StubTransport,
  now: Date = retrievedAt,
): DexScreenerPaperMarketProvider {
  return new DexScreenerPaperMarketProvider(
    transport,
    { connectionTimeoutMs: 1_000, totalTimeoutMs: 2_000 },
    () => now,
  );
}

describe('DexScreener PAPER market provider', () => {
  it('batches exact Solana identities and selects the most liquid valid pair', async () => {
    const transport = new StubTransport();
    transport.get.mockResolvedValue(response([
      {
        chainId: 'solana',
        dexId: 'low-liquidity',
        pairAddress: 'pair-low',
        baseToken: { address: wrappedSol },
        priceUsd: '0.0000012500',
        marketCap: '12500.0000',
        liquidity: { usd: '100.00' },
      },
      {
        chainId: 'solana',
        dexId: 'high-liquidity',
        pairAddress: 'pair-high',
        baseToken: { address: wrappedSol },
        priceUsd: '0.00000125',
        marketCap: '12500',
        liquidity: { usd: '900.5000' },
      },
      {
        chainId: 'solana',
        baseToken: { address: otherToken },
        priceUsd: '2',
        marketCap: '2000',
        liquidity: { usd: '50' },
      },
    ]));

    const result = await provider(transport).fetchSolana([wrappedSol, otherToken]);

    expect(transport.get).toHaveBeenCalledOnce();
    expect(transport.get.mock.calls[0]?.[0].pathname).toBe(
      `/tokens/v1/solana/${wrappedSol},${otherToken}`,
    );
    expect(result).toEqual([
      {
        available: true,
        observation: {
          networkId: 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp',
          assetAddress: wrappedSol,
          provider: 'dexscreener',
          pairAddress: 'pair-high',
          dex: 'high-liquidity',
          marketCapUsd: '12500',
          priceUsd: '0.00000125',
          liquidityUsd: '900.5',
          fetchedAt: retrievedAt,
          providerObservedAt: null,
        },
      },
      expect.anything(),
    ]);
    const second = result[1];
    expect(second?.available).toBe(true);
    expect(second?.available === true ? second.observation.assetAddress : null).toBe(otherToken);
  });

  it('does not substitute quote-token identity or fabricate market values', async () => {
    const transport = new StubTransport();
    transport.get.mockResolvedValue(response([
      {
        chainId: 'solana',
        baseToken: { address: otherToken },
        quoteToken: { address: wrappedSol },
        priceUsd: '1',
        marketCap: '100',
      },
      {
        chainId: 'solana',
        baseToken: { address: wrappedSol },
        priceUsd: '1',
        fdv: '100',
      },
    ]));

    await expect(provider(transport).fetchSolana([wrappedSol])).resolves.toEqual([
      {
        available: false,
        assetAddress: wrappedSol,
        errorCode: 'PROVIDER_MARKET_INVALID',
      },
    ]);
  });

  it('reports missing pairs and malformed market values', async () => {
    const transport = new StubTransport();
    transport.get.mockResolvedValue(response([]));
    await expect(provider(transport).fetchSolana([wrappedSol])).resolves.toEqual([
      {
        available: false,
        assetAddress: wrappedSol,
        errorCode: 'PROVIDER_PAIR_NOT_FOUND',
      },
    ]);

    transport.get.mockResolvedValue(response([
      {
        baseToken: { address: wrappedSol },
        priceUsd: '1e-7',
        marketCap: '100',
      },
    ]));
    await expect(provider(transport).fetchSolana([wrappedSol])).resolves.toEqual([
      {
        available: false,
        assetAddress: wrappedSol,
        errorCode: 'PROVIDER_MARKET_INVALID',
      },
    ]);
  });

  it('enforces the provider batch limit before making a request', async () => {
    const transport = new StubTransport();
    const addresses = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz'
      .slice(0, 31)
      .split('')
      .map((suffix) => `${'1'.repeat(31)}${suffix}`);

    await expect(provider(transport).fetchSolana(addresses)).rejects.toMatchObject({
      errorCode: 'PROVIDER_RESPONSE_MALFORMED',
    });
    expect(transport.get).not.toHaveBeenCalled();
  });

  it('honors Retry-After without exposing a response body', async () => {
    const transport = new StubTransport();
    transport.get.mockResolvedValue(response('provider-secret-body', 429, {
      'retry-after': '7',
    }));

    await expect(provider(transport).fetchSolana([wrappedSol])).rejects.toEqual(
      expect.objectContaining({
        errorCode: 'PROVIDER_RATE_LIMITED',
        retryable: true,
        statusCode: 429,
        retryAfterMs: 7_000,
        message: 'PAPER market provider request failed',
      }),
    );
  });

  it.each([
    [new PaperMarketTransportError('TIMEOUT'), 'PROVIDER_TIMEOUT'],
    [new PaperMarketTransportError('NETWORK'), 'PROVIDER_NETWORK_ERROR'],
    [new PaperMarketTransportError('RESPONSE_TOO_LARGE'), 'PROVIDER_RESPONSE_TOO_LARGE'],
  ] as const)('maps timeout, aborted, and oversized transport failures to safe codes', async (failure, errorCode) => {
    const transport = new StubTransport();
    transport.get.mockRejectedValue(failure);

    await expect(provider(transport).fetchSolana([wrappedSol])).rejects.toEqual(
      expect.objectContaining({ errorCode, message: 'PAPER market provider request failed' }),
    );
  });

  it('rejects malformed JSON and invalid token addresses', async () => {
    const transport = new StubTransport();
    transport.get.mockResolvedValue(response('{invalid-json'));

    await expect(provider(transport).fetchSolana([wrappedSol])).rejects.toBeInstanceOf(
      PaperMarketProviderError,
    );
    await expect(provider(transport).fetchSolana(['not-a-solana-address'])).rejects.toMatchObject({
      errorCode: 'PROVIDER_RESPONSE_MALFORMED',
    });
  });

  it.each([
    ['priceUsd', '1'.repeat(49)],
    ['priceUsd', `0.${'1'.repeat(31)}`],
    ['marketCap', '9'.repeat(49)],
    ['marketCap', `1.${'2'.repeat(31)}`],
    ['liquidityUsd', '8'.repeat(49)],
    ['liquidityUsd', `0.${'3'.repeat(31)}`],
  ])('rejects %s values outside PostgreSQL numeric(78,30)', async (field, value) => {
    const transport = new StubTransport();
    const pair: Record<string, unknown> = {
      chainId: 'solana',
      baseToken: { address: wrappedSol },
      priceUsd: '0.1',
      marketCap: '1000',
      liquidity: { usd: '500' },
    };

    if (field === 'liquidityUsd') {
      pair['liquidity'] = { usd: value };
    } else {
      pair[field] = value;
    }

    transport.get.mockResolvedValue(response([pair]));

    await expect(provider(transport).fetchSolana([wrappedSol])).resolves.toEqual([{
      available: false,
      assetAddress: wrappedSol,
      errorCode: 'PROVIDER_MARKET_INVALID',
    }]);
  });

  it.each([
    ['pairAddress', 'p'.repeat(129)],
    ['dexId', 'd'.repeat(65)],
    ['pairAddress', 'pair\u0000address'],
  ])('rejects unsafe %s values before persistence', async (field, value) => {
    const transport = new StubTransport();
    transport.get.mockResolvedValue(response([{
      chainId: 'solana',
      baseToken: { address: wrappedSol },
      priceUsd: '0.1',
      marketCap: '1000',
      liquidity: { usd: '500' },
      [field]: value,
    }]));

    await expect(provider(transport).fetchSolana([wrappedSol])).resolves.toEqual([{
      available: false,
      assetAddress: wrappedSol,
      errorCode: 'PROVIDER_MARKET_INVALID',
    }]);
  });

  it.each(['NaN', 'Infinity', '-1', '1e-7', ''])('rejects malformed or unsupported price %s', async (priceUsd) => {
    const transport = new StubTransport();
    transport.get.mockResolvedValue(response([{
      chainId: 'solana',
      baseToken: { address: wrappedSol },
      priceUsd,
      marketCap: '1000',
      liquidity: { usd: '500' },
    }]));

    await expect(provider(transport).fetchSolana([wrappedSol])).resolves.toEqual([{
      available: false,
      assetAddress: wrappedSol,
      errorCode: 'PROVIDER_MARKET_INVALID',
    }]);
  });

  it.each([
    { liquidity: 'malformed' },
    { liquidity: { usd: '-1' } },
    { liquidity: { usd: 'Infinity' } },
  ])('rejects malformed, negative, or non-finite liquidity', async (market) => {
    const transport = new StubTransport();
    transport.get.mockResolvedValue(response([{
      chainId: 'solana',
      baseToken: { address: wrappedSol },
      priceUsd: '0.1',
      marketCap: '1000',
      ...market,
    }]));

    await expect(provider(transport).fetchSolana([wrappedSol])).resolves.toEqual([{
      available: false,
      assetAddress: wrappedSol,
      errorCode: 'PROVIDER_MARKET_INVALID',
    }]);
  });

  it('preserves high-precision JSON number source without floating-point conversion', async () => {
    const transport = new StubTransport();
    const exactPrice = '0.123456789012345678901234567891';
    const exactMarketCap = `${'9'.repeat(48)}.${'8'.repeat(30)}`;
    const exactLiquidity = `123456789.${'7'.repeat(30)}`;
    transport.get.mockResolvedValue(response(
      '[{"chainId":"solana","pairAddress":"pair","dexId":"dex",'
      + '"baseToken":{"address":"' + wrappedSol + '"},'
      + '"priceUsd":' + exactPrice + ',"marketCap":' + exactMarketCap
      + ',"liquidity":{"usd":' + exactLiquidity + '}}]',
    ));

    const result = await provider(transport).fetchSolana([wrappedSol]);

    expect(result[0]).toMatchObject({
      available: true,
      observation: {
        priceUsd: exactPrice,
        marketCapUsd: exactMarketCap,
        liquidityUsd: exactLiquidity,
      },
    });
  });

  it.each([
    ['999999999999999999999999', DEXSCREENER_MAX_RETRY_AFTER_MS],
    ['9999999', DEXSCREENER_MAX_RETRY_AFTER_MS],
    ['invalid', null],
    ['-1', null],
  ])('bounds or rejects Retry-After value %s', async (retryAfter, expected) => {
    const transport = new StubTransport();
    transport.get.mockResolvedValue(response([], 429, { 'retry-after': retryAfter }));

    await expect(provider(transport).fetchSolana([wrappedSol])).rejects.toMatchObject({
      errorCode: 'PROVIDER_RATE_LIMITED',
      statusCode: 429,
      retryAfterMs: expected,
    });
  });

  it('caps Retry-After HTTP dates at the documented one-hour ceiling', async () => {
    const transport = new StubTransport();
    transport.get.mockResolvedValue(response([], 429, {
      'retry-after': new Date(retrievedAt.getTime() + 86_400_000).toUTCString(),
    }));

    await expect(provider(transport).fetchSolana([wrappedSol])).rejects.toMatchObject({
      retryAfterMs: DEXSCREENER_MAX_RETRY_AFTER_MS,
    });
  });

  it.each([0, 99, 600, 999, Number.NaN, 200.5])('normalizes unexpected HTTP status %s to null', async (status) => {
    const transport = new StubTransport();
    transport.get.mockResolvedValue(response([], status));

    await expect(provider(transport).fetchSolana([wrappedSol])).rejects.toMatchObject({
      errorCode: 'PROVIDER_HTTP_ERROR',
      retryable: false,
      statusCode: null,
    });
  });

  it('rejects an oversized response body before parsing', async () => {
    const transport = new StubTransport();
    transport.get.mockResolvedValue(response('x'.repeat(DEXSCREENER_MAX_RESPONSE_BYTES + 1)));

    await expect(provider(transport).fetchSolana([wrappedSol])).rejects.toMatchObject({
      errorCode: 'PROVIDER_RESPONSE_TOO_LARGE',
      retryable: false,
    });
  });

  it('selects equal-liquidity pairs deterministically by stable pair identity', async () => {
    const transport = new StubTransport();
    const pairA = {
      chainId: 'solana', dexId: 'raydium', pairAddress: 'pair-a',
      baseToken: { address: wrappedSol }, priceUsd: '1', marketCap: '1000',
      liquidity: { usd: '500' },
    };
    const pairZ = {
      chainId: 'solana', dexId: 'raydium', pairAddress: 'pair-z',
      baseToken: { address: wrappedSol }, priceUsd: '2', marketCap: '2000',
      liquidity: { usd: '500' },
    };

    transport.get.mockResolvedValueOnce(response([pairZ, pairA]));
    const first = await provider(transport).fetchSolana([wrappedSol]);
    transport.get.mockResolvedValueOnce(response([pairA, pairZ]));
    const second = await provider(transport).fetchSolana([wrappedSol]);

    expect(first[0]).toMatchObject({ observation: { pairAddress: 'pair-a', priceUsd: '1' } });
    expect(second).toEqual(first);
  });

});
