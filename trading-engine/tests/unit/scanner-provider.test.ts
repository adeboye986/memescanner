import { describe, expect, it, vi } from 'vitest';

import { BirdeyeScannerProvider } from '../../src/infrastructure/scanning/birdeye-scanner-provider.js';
import { DexScreenerScannerProvider } from '../../src/infrastructure/scanning/dexscreener-scanner-provider.js';
import { GeckoTerminalScannerProvider } from '../../src/infrastructure/scanning/geckoterminal-scanner-provider.js';
import { GoPlusScannerProvider } from '../../src/infrastructure/scanning/goplus-scanner-provider.js';
import {
  SCANNER_MAX_RESPONSE_BYTES,
  ScannerHttpClient,
  type ScannerProviderError,
} from '../../src/infrastructure/scanning/scanner-http-client.js';
import { evaluateHolderRisk } from '../../src/infrastructure/scanning/solana-rpc-scanner-provider.js';

function fetchUsing(handler: (url: URL, init: RequestInit) => Response | Promise<Response>): typeof fetch {
  return vi.fn(async (input: Parameters<typeof fetch>[0], init?: RequestInit) => {
    const url = input instanceof URL
      ? input
      : input instanceof Request
        ? new URL(input.url)
        : new URL(input);

    return await handler(url, init ?? {});
  });
}

describe('scanner provider adapters', () => {
  it('normalizes Birdeye fields and sends only the required provider headers', async () => {
    const fetchMock = fetchUsing((url, init) => {
      expect(url.origin).toBe('https://public-api.birdeye.so');
      expect(url.pathname).toBe('/defi/token_overview');
      expect(url.searchParams.get('address')).toBe('mint-address');
      expect(new Headers(init.headers).get('X-API-KEY')).toBe('test-key');
      expect(new Headers(init.headers).get('x-chain')).toBe('solana');

      return Response.json({
        data: {
          symbol: 'TOK',
          name: 'Token',
          price: '0.001',
          marketCap: '12000',
          liquidity: 6000,
          holder: '25',
          v1m: 900,
          buy1m: '12',
          sell1m: 4,
          uniqueWallet5m: 17,
          priceChange5mPercent: '8.5',
        },
      });
    });
    const provider = new BirdeyeScannerProvider(
      new ScannerHttpClient(fetchMock),
      new URL('https://public-api.birdeye.so'),
      'test-key',
      1_000,
    );

    await expect(provider.overview('mint-address')).resolves.toEqual({
      address: 'mint-address',
      symbol: 'TOK',
      name: 'Token',
      price: 0.001,
      marketCap: 12000,
      liquidity: 6000,
      holders: 25,
      volume1m: 900,
      buys1m: 12,
      sells1m: 4,
      uniqueWallets5m: 17,
      priceChange5mPercent: 8.5,
    });
  });

  it('deduplicates Birdeye discovery listings before scan accounting', async () => {
    const provider = new BirdeyeScannerProvider(
      new ScannerHttpClient(fetchUsing(() => Response.json({
        data: {
          items: [
            { address: 'mint-address', symbol: 'ONE', name: 'First', liquidity: 500 },
            { address: 'mint-address', symbol: 'DUP', name: 'Duplicate', liquidity: 900 },
            { address: 'other-mint', symbol: 'TWO', name: 'Second', liquidity: 600 },
          ],
        },
      }))),
      new URL('https://public-api.birdeye.so'),
      'test-key',
      1_000,
    );

    await expect(provider.newListings()).resolves.toEqual([
      { address: 'mint-address', symbol: 'ONE', name: 'First', liquidity: 500 },
      { address: 'other-mint', symbol: 'TWO', name: 'Second', liquidity: 600 },
    ]);
  });

  it('deduplicates profiles and selects the most liquid requested-token base pair', async () => {
    const fetchMock = fetchUsing((url) => {
      if (url.pathname === '/token-profiles/latest/v1') {
        return Response.json([
          { chainId: 'ethereum', tokenAddress: '0xABCD' },
          { chainId: 'ethereum', tokenAddress: '0xabcd' },
          { chainId: 'solana', tokenAddress: 'SolanaMint' },
        ]);
      }

      return Response.json([
        {
          baseToken: { address: '0xABCD', symbol: 'LOW', name: 'Low' },
          liquidity: { usd: 100 },
          marketCap: 10_000,
          volume: { m5: 500 },
          txns: { m5: { buys: 4, sells: 3 } },
        },
        {
          baseToken: { address: '0xAbCd', symbol: 'HIGH', name: 'High' },
          liquidity: { usd: 2_000 },
          marketCap: '15000',
          priceUsd: '0.01',
          volume: { m5: '750' },
          txns: { m5: { buys: '8', sells: 2 } },
          priceChange: { m5: '4.5' },
          pairAddress: 'pair',
          dexId: 'dex',
        },
        {
          baseToken: { address: '0xother', symbol: 'OTHER' },
          liquidity: { usd: 9_999_999 },
        },
      ]);
    });
    const provider = new DexScreenerScannerProvider(new ScannerHttpClient(fetchMock), 1_000);

    await expect(provider.latestProfiles('ethereum')).resolves.toEqual([{ address: '0xabcd' }]);
    await expect(provider.market('0xabcd', 'ethereum')).resolves.toMatchObject({
      available: true,
      requestedTokenIsBase: true,
      symbol: 'HIGH',
      marketCap: 15000,
      liquidityUsd: 2000,
      volume5m: 750,
      buys5m: 8,
      sells5m: 2,
      priceChange5m: 4.5,
      pairAddress: 'pair',
      dex: 'dex',
    });
  });

  it('preserves a quote-only Dex pair as available but never attributes base metrics to it', async () => {
    const provider = new DexScreenerScannerProvider(
      new ScannerHttpClient(fetchUsing(() => Response.json([{
        baseToken: { address: 'other-mint', symbol: 'OTHER', name: 'Other' },
        quoteToken: { address: 'requested-mint', symbol: 'REQUESTED', name: 'Requested' },
        liquidity: { usd: 5_000 },
        marketCap: 99_999,
        priceUsd: '2',
        volume: { m5: 750 },
        txns: { m5: { buys: 8, sells: 2 } },
        priceChange: { m5: 4.5 },
        pairAddress: 'quote-pair',
        dexId: 'raydium',
      }]))),
      1_000,
    );

    await expect(provider.market('requested-mint', 'solana')).resolves.toEqual({
      address: 'requested-mint',
      available: true,
      requestedTokenIsBase: false,
      symbol: null,
      name: null,
      priceUsd: null,
      marketCap: 0,
      liquidityUsd: 5_000,
      volume5m: 750,
      buys5m: 8,
      sells5m: 2,
      priceChange5m: null,
      pairAddress: 'quote-pair',
      dex: 'raydium',
    });
  });

  it('validates GoPlus Ethereum flags without treating unavailable data as safe', async () => {
    const safeProvider = new GoPlusScannerProvider(
      new ScannerHttpClient(fetchUsing(() => Response.json({
        code: 1,
        result: {
          '0xabcd': {
            is_open_source: '1',
            is_mintable: '0',
            owner_change_balance: '0',
            transfer_pausable: '0',
            is_honeypot: '0',
            cannot_buy: '0',
            cannot_sell_all: '0',
          },
        },
      }))),
      1_000,
    );

    await expect(safeProvider.ethereum('0xABCD')).resolves.toMatchObject({
      available: true,
      passed: true,
      score: 100,
      risks: [],
    });

    const unavailableProvider = new GoPlusScannerProvider(
      new ScannerHttpClient(fetchUsing(() => Response.json({
        code: 1,
        result: { '0xabcd': { is_open_source: 'unknown' } },
      }))),
      1_000,
    );

    await expect(unavailableProvider.ethereum('0xABCD')).resolves.toEqual({
      available: false,
      passed: null,
      score: null,
      risks: [],
      checks: {},
    });
  });

  it('discovers Ethereum base tokens from GeckoTerminal new pools with stable deduplication', async () => {
    const address = '0xabcdefabcdefabcdefabcdefabcdefabcdefabcd';
    const provider = new GeckoTerminalScannerProvider(
      new ScannerHttpClient(fetchUsing((url) => {
        expect(url.origin).toBe('https://api.geckoterminal.com');
        expect(url.pathname).toBe('/api/v2/networks/eth/new_pools');
        expect(url.searchParams.get('page')).toBe('1');

        return Response.json({
          data: [
            { relationships: { base_token: { data: { id: `eth_0x${address.slice(2).toUpperCase()}` } } } },
            { relationships: { base_token: { data: { id: `eth_${address}` } } } },
            { relationships: { base_token: { data: { id: 'eth_invalid' } } } },
          ],
        });
      })),
      1_000,
    );

    await expect(provider.latestEthereumTokens()).resolves.toEqual([{ address }]);
  });

  it('bounds responses and classifies retryable transport outcomes without exposing bodies', async () => {
    const rateLimited = new ScannerHttpClient(fetchUsing(() => new Response(
      'provider secret detail',
      { status: 429 },
    )));

    await expect(rateLimited.json(new URL('https://provider.example/data'), { timeoutMs: 1_000 }))
      .rejects.toMatchObject<Partial<ScannerProviderError>>({
        code: 'PROVIDER_RATE_LIMITED',
        retryable: true,
        statusCode: 429,
        message: 'Scanner provider request failed',
      });

    const oversized = new ScannerHttpClient(fetchUsing(() => new Response('{}', {
      headers: { 'content-length': String(SCANNER_MAX_RESPONSE_BYTES + 1) },
    })));

    await expect(oversized.json(new URL('https://provider.example/data'), { timeoutMs: 1_000 }))
      .rejects.toMatchObject({ code: 'PROVIDER_RESPONSE_TOO_LARGE', retryable: false });

    await expect(rateLimited.json(new URL('http://provider.example/data'), { timeoutMs: 1_000 }))
      .rejects.toMatchObject({ code: 'PROVIDER_URL_INVALID', retryable: false });
  });

  it('preserves the Laravel holder concentration safety thresholds', () => {
    expect(evaluateHolderRisk(3, 18, 30)).toMatchObject({
      passed: true,
      score: 100,
      level: 'low',
    });
    expect(evaluateHolderRisk(11, 55, 75)).toMatchObject({
      passed: false,
      score: 0,
      level: 'critical',
    });
  });
});
