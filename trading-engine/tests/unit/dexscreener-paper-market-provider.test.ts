import { describe, expect, it, vi } from 'vitest';

import {
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
  ] as const)('maps transport failures to safe codes', async (failure, errorCode) => {
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
});
