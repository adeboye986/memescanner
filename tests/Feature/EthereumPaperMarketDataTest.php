<?php

namespace Tests\Feature;

use App\Models\PaperPosition;
use App\Services\DexScreenerService;
use App\Services\EthereumPaperMarketData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EthereumPaperMarketDataTest extends TestCase
{
    private const TOKEN = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const POOL = '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const QUOTE = '0xcccccccccccccccccccccccccccccccccccccccc';

    #[DataProvider('poolIds')]
    public function test_missing_primary_pairs_fall_back_to_exact_original_pool(string $pool): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.dexscreener.com/*' => Http::response([]),
            'api.geckoterminal.com/api/v2/networks/eth/pools/'.$pool => Http::response(['data' => $this->pool($pool)])]);

        $result = app(EthereumPaperMarketData::class)->fetch([$this->position($pool)]);

        $this->assertSame('geckoterminal', $result['observations'][1]['provider']);
        $this->assertSame($pool, $result['observations'][1]['pair_address']);
        $this->assertFalse($result['observations'][1]['pool_switched']);
        $this->assertNull($result['observations'][1]['market_cap']);
        $this->assertSame(999999.0, $result['observations'][1]['fdv']);
        Http::assertSentCount(2);
    }

    public static function poolIds(): array
    {
        return [['0x'.str_repeat('b', 40)], ['0x'.str_repeat('d', 64)]];
    }

    public function test_primary_prefers_original_pool_without_changing_scanner_batch_selection(): void
    {
        Http::preventStrayRequests();
        $other = [...$this->pair(), 'pairAddress' => '0x'.str_repeat('d', 64), 'liquidity' => ['usd' => 999999]];
        Http::fake(['api.dexscreener.com/*' => Http::response([$other, $this->pair()])]);

        $tracked = app(EthereumPaperMarketData::class)->fetch([$this->position(strtoupper(self::POOL))]);
        $scanner = app(DexScreenerService::class)->analyzeTokens([self::TOKEN], 'ethereum');

        $this->assertSame(self::POOL, $tracked['observations'][1]['pair_address']);
        $this->assertSame($other['pairAddress'], $scanner[self::TOKEN]['pair_address']);
        $this->assertSame([], $tracked['provider_errors']);
        Http::assertSentCount(2);
    }

    public function test_pool_switch_is_recorded_and_primary_does_not_call_fallback_when_valid(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.dexscreener.com/*' => Http::response([$this->pair()])]);

        $result = app(EthereumPaperMarketData::class)->fetch([$this->position('0x'.str_repeat('d', 64))]);

        $this->assertTrue($result['observations'][1]['pool_switched']);
        $this->assertSame('original_pool_unavailable_in_primary_response', $result['observations'][1]['selection_reason']);
        Http::assertSentCount(1);
    }

    public function test_missing_original_pool_uses_valid_token_pool_list(): void
    {
        Http::preventStrayRequests();
        $other = '0x'.str_repeat('d', 64);
        Http::fake(['api.dexscreener.com/*' => Http::response([]),
            'api.geckoterminal.com/api/v2/networks/eth/pools/*' => Http::response([], 404),
            'api.geckoterminal.com/api/v2/networks/eth/tokens/*/pools' => Http::response(['data' => [$this->pool($other)]])]);

        $result = app(EthereumPaperMarketData::class)->fetch([$this->position()]);

        $this->assertSame($other, $result['observations'][1]['pair_address']);
        $this->assertTrue($result['observations'][1]['pool_switched']);
        Http::assertSentCount(3);
    }

    #[DataProvider('invalidPrimary')]
    public function test_invalid_primary_uses_fallback(string $field, mixed $value): void
    {
        Http::preventStrayRequests();
        $pair = $this->pair();
        data_set($pair, $field, $value);
        Http::fake(['api.dexscreener.com/*' => Http::response([$pair]),
            'api.geckoterminal.com/*' => Http::response(['data' => $this->pool()])]);

        $result = app(EthereumPaperMarketData::class)->fetch([$this->position()]);

        $this->assertSame('geckoterminal', $result['observations'][1]['provider']);
    }

    public static function invalidPrimary(): array
    {
        return [['chainId', 'solana'], ['baseToken.address', self::QUOTE], ['pairAddress', '0x123'],
            ['quoteToken.address', 'wrong'], ['liquidity.usd', 0], ['liquidity.usd', 'bad']];
    }

    #[DataProvider('invalidFallback')]
    public function test_malformed_fallback_cannot_supply_an_observation(string $field, mixed $value): void
    {
        Http::preventStrayRequests();
        $pool = $this->pool();
        data_set($pool, $field, $value);
        Http::fake(['api.dexscreener.com/*' => Http::response([]),
            'api.geckoterminal.com/api/v2/networks/eth/pools/*' => Http::response(['data' => $pool]),
            'api.geckoterminal.com/api/v2/networks/eth/tokens/*/pools' => Http::response(['data' => [$pool]])]);

        $result = app(EthereumPaperMarketData::class)->fetch([$this->position()]);

        $this->assertFalse($result['observations'][1]['available']);
    }

    public static function invalidFallback(): array
    {
        return [['id', 'polygon_'.self::POOL], ['type', 'token'], ['attributes.address', '0x123'],
            ['relationships.base_token.data.id', 'eth_'.self::QUOTE], ['relationships.quote_token.data.id', 'solana_bad'],
            ['attributes.base_token_price_usd', 0], ['attributes.base_token_price_usd', 'NaN'],
            ['attributes.base_token_price_usd', '1e999'], ['attributes.base_token_price_usd', []]];
    }

    public function test_timeout_in_primary_and_rate_limited_fallback_are_reported(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.dexscreener.com/*' => Http::failedConnection(), 'api.geckoterminal.com/*' => Http::response([], 429)]);

        $result = app(EthereumPaperMarketData::class)->fetch([$this->position()]);

        $this->assertFalse($result['observations'][1]['available']);
        $this->assertTrue($result['rate_limited']);
        $this->assertSame(2, $result['failures']);
    }

    #[DataProvider('primaryFailures')]
    public function test_primary_failure_still_uses_valid_fallback(string $failure): void
    {
        Http::preventStrayRequests();
        $response = match ($failure) {
            'timeout' => Http::failedConnection(),
            'malformed' => Http::response(['not' => 'a list']),
            default => Http::response([], (int) $failure),
        };
        Http::fake(['api.dexscreener.com/*' => $response, 'api.geckoterminal.com/*' => Http::response(['data' => $this->pool()])]);

        $result = app(EthereumPaperMarketData::class)->fetch([$this->position()]);

        $this->assertSame('geckoterminal', $result['observations'][1]['provider']);
        $this->assertSame(1, $result['failures']);
        $this->assertSame([[
            'provider' => 'dexscreener', 'target_type' => 'token_batch',
            'http_status' => is_numeric($failure) ? (int) $failure : null,
            'category' => match ($failure) {
                'timeout' => 'connection_error', 'malformed' => 'malformed_response', default => 'http_error',
            },
        ]], $result['provider_errors']);
        Http::assertSentCount(2);
    }

    public static function primaryFailures(): array
    {
        return [['timeout'], ['malformed'], ['429'], ['503']];
    }

    public function test_invalid_identity_does_not_call_providers(): void
    {
        Http::preventStrayRequests();
        $position = $this->position();
        $position->address = 'solana-token';

        $result = app(EthereumPaperMarketData::class)->fetch([$position]);

        $this->assertFalse($result['observations'][1]['available']);
        Http::assertNothingSent();
    }

    public function test_quote_token_fallback_uses_quote_price_and_never_base_market_cap_or_fdv(): void
    {
        Http::preventStrayRequests();
        $pool = $this->pool();
        $pool['relationships']['base_token']['data']['id'] = 'eth_'.self::QUOTE;
        $pool['relationships']['quote_token']['data']['id'] = 'eth_'.self::TOKEN;
        $pool['attributes']['quote_token_price_usd'] = '0.25';
        $pool['attributes']['market_cap_usd'] = 1000000000;
        Http::fake(['api.dexscreener.com/*' => Http::response([]), 'api.geckoterminal.com/*' => Http::response(['data' => $pool])]);

        $result = app(EthereumPaperMarketData::class)->fetch([$this->position()])['observations'][1];

        $this->assertTrue($result['requested_token_identity_verified']);
        $this->assertFalse($result['requested_token_is_base']);
        $this->assertSame(0.25, $result['price_usd']);
        $this->assertNull($result['market_cap']);
        $this->assertNull($result['fdv']);
    }

    #[DataProvider('illiquidOriginalPools')]
    public function test_unusable_original_pool_does_not_mask_usable_alternative(string $provider, mixed $liquidity): void
    {
        config(['services.trading.paper_market.ethereum.minimum_liquidity_usd' => 1000]);
        $other = '0x'.str_repeat('d', 64);
        Http::preventStrayRequests();
        if ($provider === 'dexscreener') {
            $original = $this->pair();
            $original['liquidity']['usd'] = $liquidity;
            Http::fake(['api.dexscreener.com/*' => Http::response([$original, [...$this->pair(), 'pairAddress' => $other]])]);
        } else {
            $original = $this->pool();
            $original['attributes']['reserve_in_usd'] = $liquidity;
            Http::fake(['api.dexscreener.com/*' => Http::response([]),
                'api.geckoterminal.com/api/v2/networks/eth/pools/*' => Http::response(['data' => $original]),
                'api.geckoterminal.com/api/v2/networks/eth/tokens/*/pools' => Http::response(['data' => [$original, $this->pool($other)]])]);
        }

        $data = app(EthereumPaperMarketData::class)->fetch([$this->position()])['observations'][1];

        $this->assertSame($provider, $data['provider']);
        $this->assertSame($other, $data['pair_address']);
        $this->assertSame(self::POOL, $data['original_pair_address']);
        $this->assertSame($liquidity === null || $liquidity === 0 ? null : (float) $liquidity, $data['original_liquidity_usd']);
        $this->assertSame('original_pool_not_usable', $data['selection_reason']);
        $this->assertTrue($data['pool_switched']);
        $this->assertStringContainsString('do not prove', $data['pool_execution_limitation']);
        Http::assertSentCount($provider === 'dexscreener' ? 1 : 3);
    }

    public static function illiquidOriginalPools(): array
    {
        return [['dexscreener', null], ['dexscreener', 0], ['dexscreener', 500],
            ['geckoterminal', null], ['geckoterminal', 0], ['geckoterminal', 500]];
    }

    public function test_reused_fallback_retains_acquisition_time(): void
    {
        $this->freezeTime();
        $acquired = now()->toIso8601String();
        $first = $this->position();
        $second = $this->position();
        $second->id = 2;
        Http::preventStrayRequests();
        Http::fake(['api.dexscreener.com/*' => Http::response([]), 'api.geckoterminal.com/*' => Http::response(['data' => $this->pool()])]);

        $result = app(EthereumPaperMarketData::class)->fetch([$first, $second], observe: function (): void {
            $this->travel(3)->seconds();
        });

        $this->assertSame($acquired, $result['observations'][1]['fetched_at']);
        $this->assertSame($acquired, $result['observations'][2]['fetched_at']);
        Http::assertSentCount(2);
    }

    public function test_errors_attribute_each_provider_and_target_without_exposing_response_bodies(): void
    {
        Http::preventStrayRequests();
        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $requests[] = $request->url();
            $status = count($requests) === 1 ? 503 : (count($requests) === 2 ? 404 : 429);

            return Http::response(['api_key' => 'sensitive-fixture', 'body' => 'private response'], $status);
        });

        $result = app(EthereumPaperMarketData::class)->fetch([$this->position()]);

        $this->assertSame([
            ['provider' => 'dexscreener', 'target_type' => 'token_batch', 'http_status' => 503, 'category' => 'http_error'],
            ['provider' => 'geckoterminal', 'target_type' => 'original_pool', 'http_status' => 404, 'category' => 'http_error'],
            ['provider' => 'geckoterminal', 'target_type' => 'token_pools', 'http_status' => 429, 'category' => 'http_error'],
        ], $result['provider_errors']);
        $this->assertSame([
            'https://api.dexscreener.com/tokens/v1/ethereum/'.self::TOKEN,
            'https://api.geckoterminal.com/api/v2/networks/eth/pools/'.self::POOL,
            'https://api.geckoterminal.com/api/v2/networks/eth/tokens/'.self::TOKEN.'/pools',
        ], $requests);
        $this->assertSame(3, $result['requests']);
        $this->assertSame(3, $result['failures']);
        $this->assertTrue($result['rate_limited']);
        $this->assertFalse($result['observations'][1]['available']);
        Http::assertSentCount(3);
    }

    #[DataProvider('unsafeProviderMessages')]
    public function test_unrecognized_exception_messages_are_never_returned_or_used_as_status(string $message): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.dexscreener.com/*' => function () use ($message) {
            throw new \RuntimeException($message, 503);
        }, 'api.geckoterminal.com/*' => Http::response(['data' => $this->pool()])]);

        $result = app(EthereumPaperMarketData::class)->fetch([$this->position()]);

        $this->assertSame([['provider' => 'dexscreener', 'target_type' => 'token_batch', 'http_status' => null,
            'category' => 'provider_request_failed']], $result['provider_errors']);
        $this->assertSame('geckoterminal', $result['observations'][1]['provider']);
        $this->assertSame(str_contains($message, '429'), $result['rate_limited']);
        $this->assertSame(1, $result['failures']);
        $this->assertSame(2, $result['requests']);
        Http::assertSentCount(1);
    }

    public static function unsafeProviderMessages(): array
    {
        return [
            ['Request to https://user:password@example.test/?api_key=secret429 failed'],
            ['DexScreener PAPER API error: 503 response body with credentials'],
            ['DexScreener PAPER API error: 5030'],
            ['DexScreener PAPER API error: 999'],
        ];
    }

    public function test_gecko_connection_and_malformed_errors_preserve_fallback_sequence(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.dexscreener.com/*' => Http::response([]),
            'api.geckoterminal.com/api/v2/networks/eth/pools/*' => Http::failedConnection('https://user:password@example.test/?api_key=secret'),
            'api.geckoterminal.com/api/v2/networks/eth/tokens/*/pools' => Http::response(['data' => 'secret malformed body'])]);

        $result = app(EthereumPaperMarketData::class)->fetch([$this->position()]);

        $this->assertSame([
            ['provider' => 'geckoterminal', 'target_type' => 'original_pool', 'http_status' => null, 'category' => 'connection_error'],
            ['provider' => 'geckoterminal', 'target_type' => 'token_pools', 'http_status' => null, 'category' => 'malformed_response'],
        ], $result['provider_errors']);
        $this->assertSame(2, $result['failures']);
        $this->assertFalse($result['rate_limited']);
        $this->assertFalse($result['observations'][1]['available']);
        Http::assertSentCount(3);
    }

    #[DataProvider('cooldownTargets')]
    public function test_gecko_cooldown_is_shared_across_instances_and_expires(string $pool, string $targetType): void
    {
        $this->freezeTime();
        config(['cache.stores.paper_cooldown_test' => ['driver' => 'array'],
            'services.trading.paper_tracker_cache_store' => 'paper_cooldown_test',
            'services.trading.paper_market.ethereum.geckoterminal_cooldown_seconds' => 60]);
        Http::preventStrayRequests();
        $payload = $pool === '' ? ['data' => [$this->pool()]] : ['data' => $this->pool()];
        Http::fake(['api.dexscreener.com/*' => Http::sequence()->push([])->push([])->push([$this->pair()])->push([]),
            'api.geckoterminal.com/*' => Http::sequence()->push(['private' => 'not exposed'], 429)->push($payload)]);

        $limited = app(EthereumPaperMarketData::class)->fetch([$this->position($pool)]);
        $this->assertSame(2, $limited['requests']);
        $this->assertSame(1, $limited['failures']);
        $this->assertSame(429, $limited['provider_errors'][0]['http_status']);
        $this->assertTrue($limited['rate_limited']);
        $this->assertTrue(Cache::store('paper_cooldown_test')->has('paper-market.ethereum.geckoterminal.cooldown'));
        $this->assertFalse(Cache::store('array')->has('paper-market.ethereum.geckoterminal.cooldown'));

        $this->travel(59)->seconds();
        $skipped = app(EthereumPaperMarketData::class)->fetch([$this->position($pool)]);
        $this->assertSame(1, $skipped['requests']);
        $this->assertSame(0, $skipped['failures']);
        $this->assertSame([], $skipped['provider_errors']);
        $this->assertFalse($skipped['rate_limited']);
        $this->assertSame([['provider' => 'geckoterminal', 'target_type' => $targetType,
            'category' => 'rate_limit_cooldown']], $skipped['provider_skips']);
        $this->assertFalse($skipped['observations'][1]['available']);
        $this->assertSame('geckoterminal_cooldown', $skipped['observations'][1]['reason']);

        $primary = app(EthereumPaperMarketData::class)->fetch([$this->position($pool)]);
        $this->assertSame('dexscreener', $primary['observations'][1]['provider']);
        $this->assertSame(1, $primary['requests']);
        $this->assertSame([], $primary['provider_skips']);
        $this->travel(1)->seconds();
        $resumed = app(EthereumPaperMarketData::class)->fetch([$this->position($pool)]);
        $this->assertSame('geckoterminal', $resumed['observations'][1]['provider']);
        $this->assertSame(2, $resumed['requests']);
        $this->assertSame(0, $resumed['failures']);
        $this->assertSame([], $resumed['provider_skips']);
        Http::assertSentCount(6);
    }

    public static function cooldownTargets(): array
    {
        return [[self::POOL, 'original_pool'], ['', 'token_pools']];
    }

    public function test_cooldown_duration_is_configurable(): void
    {
        $this->freezeTime();
        config(['services.trading.paper_market.ethereum.geckoterminal_cooldown_seconds' => 10]);
        Http::preventStrayRequests();
        Http::fake(['api.dexscreener.com/*' => Http::response([]),
            'api.geckoterminal.com/*' => Http::sequence()->push([], 429)->push(['data' => $this->pool()])]);
        app(EthereumPaperMarketData::class)->fetch([$this->position()]);
        $this->travel(9)->seconds();
        $this->assertSame(1, app(EthereumPaperMarketData::class)->fetch([$this->position()])['requests']);
        $this->travel(1)->seconds();
        $result = app(EthereumPaperMarketData::class)->fetch([$this->position()]);
        $this->assertSame('geckoterminal', $result['observations'][1]['provider']);
        Http::assertSentCount(5);
    }

    #[DataProvider('nonCooldownFailures')]
    public function test_only_known_gecko_http_429_starts_shared_cooldown(string $failure): void
    {
        $this->freezeTime();
        Http::preventStrayRequests();
        Http::fake(['api.dexscreener.com/*' => $failure === 'dex_429' ? Http::response([], 429) : Http::response([]),
            'api.geckoterminal.com/*' => match ($failure) {
                'unrecognized_429' => function () {
                    throw new \RuntimeException('Network error https://example.test/?secret=429');
                },
                'gecko_503' => Http::response([], 503),
                default => Http::response(['data' => $this->pool()]),
            }]);

        $first = app(EthereumPaperMarketData::class)->fetch([$this->position()]);
        $second = app(EthereumPaperMarketData::class)->fetch([$this->position()]);

        $this->assertFalse(Cache::store('array')->has('paper-market.ethereum.geckoterminal.cooldown'));
        $this->assertSame([], $second['provider_skips']);
        $this->assertSame($first['requests'], $second['requests']);
        $this->assertSame($first['failures'], $second['failures']);
        $this->assertGreaterThan(1, $second['requests']);
    }

    public static function nonCooldownFailures(): array
    {
        return [['dex_429'], ['gecko_503'], ['unrecognized_429']];
    }

    private function position(string $pool = self::POOL): PaperPosition
    {
        return new PaperPosition(['id' => 1, 'chain' => 'ethereum', 'address' => self::TOKEN, 'entry_price' => 1, 'meta' => ['pair_address' => $pool]]);
    }

    private function pair(): array
    {
        return ['chainId' => 'ethereum', 'pairAddress' => self::POOL, 'baseToken' => ['address' => self::TOKEN],
            'quoteToken' => ['address' => self::QUOTE], 'priceUsd' => '1', 'marketCap' => 100000, 'liquidity' => ['usd' => 50000]];
    }

    private function pool(string $pool = self::POOL): array
    {
        return ['id' => 'eth_'.$pool, 'type' => 'pool',
            'attributes' => ['address' => $pool, 'base_token_price_usd' => '0.5', 'market_cap_usd' => null, 'fdv_usd' => '999999', 'reserve_in_usd' => '5000'],
            'relationships' => ['base_token' => ['data' => ['type' => 'token', 'id' => 'eth_'.self::TOKEN]],
                'quote_token' => ['data' => ['type' => 'token', 'id' => 'eth_'.self::QUOTE]]]];
    }
}
