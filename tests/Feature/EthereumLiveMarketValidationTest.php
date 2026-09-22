<?php

namespace Tests\Feature;

use App\Models\TradeOpportunity;
use App\Services\EthereumOpportunityRevalidationService;
use App\Services\GoPlusEthereumService;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EthereumLiveMarketValidationTest extends TestCase
{
    private const TOKEN = '0xabcdefabcdefabcdefabcdefabcdefabcdefabcd';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->mock(GoPlusEthereumService::class)->shouldReceive('evaluateToken')->andReturn([
            'available' => true, 'passed' => true, 'chain' => 'ethereum', 'address' => self::TOKEN,
        ]);
    }

    #[DataProvider('numbers')]
    public function test_real_parser_rejects_unusable_required_metrics(mixed $value, bool $valid): void
    {
        foreach (['marketCap', 'liquidity.usd', 'volume.m5'] as $field) {
            $pair = $this->pair();
            data_set($pair, $field, $value);
            $result = $this->check([$pair]);
            $this->assertSame($valid, $result['passed'], $field);
            if (! $valid) {
                $this->assertSame('malformed', $result['failure_class']);
            }
        }
    }

    public static function numbers(): array
    {
        return [['10000', true], ['10000.5', true], [10000, true], [10000.5, true],
            ['10000invalid', false], ['', false], [' ', false], [null, false], [[], false], [new \stdClass, false], [true, false], [false, false],
            [['value' => 10000], false], ['NaN', false], ['Infinity', false], ['1.2.3', false], [-10000, false], ['-10000', false],
            ['1e9999', false], [' 10000', false], ['10000 ', false]];
    }

    public function test_missing_required_metrics_fail_closed(): void
    {
        foreach (['marketCap', 'liquidity', 'volume'] as $field) {
            $pair = $this->pair();
            unset($pair[$field]);
            $this->assertFalse($this->check([$pair])['passed']);
        }
    }

    public function test_multiple_pairs_select_highest_liquidity_matching_ethereum_base(): void
    {
        $low = $this->pair();
        $low['liquidity']['usd'] = 1000;
        $high = $this->pair();
        $high['pairAddress'] = '0x'.str_repeat('A', 40);
        $unrelated = $this->pair();
        $unrelated['baseToken']['address'] = '0x'.str_repeat('9', 40);
        $unrelated['quoteToken']['address'] = self::TOKEN;
        $unrelated['liquidity']['usd'] = 9999999;
        $otherChain = $this->pair();
        $otherChain['chainId'] = 'solana';
        $otherChain['liquidity']['usd'] = 99999999;
        $audit = $this->check([$unrelated, $otherChain, $low, $high]);
        $this->assertTrue($audit['passed']);
        $this->assertSame('0x'.str_repeat('a', 40), $audit['market']['pair_address']);
        $this->assertFalse($this->check([$otherChain])['passed']);
    }

    public function test_malformed_matching_pool_liquidity_cannot_influence_selection(): void
    {
        $malformed = $this->pair();
        $malformed['liquidity']['usd'] = '999999invalid';
        $this->assertSame('malformed', $this->check([$this->pair(), $malformed])['failure_class']);
    }

    #[DataProvider('boundaries')]
    public function test_momentum_boundaries(string $field, mixed $value, bool $passes): void
    {
        $pair = $this->pair();
        data_set($pair, $field, $value);
        $audit = $this->check([$pair]);
        $this->assertSame($passes, $audit['passed']);
        $this->assertSame($passes ? null : 'unsafe', $audit['failure_class']);
    }

    public static function boundaries(): array
    {
        return [['marketCap', 4999, false], ['marketCap', 5000, true], ['marketCap', 100000, true], ['marketCap', 100001, false],
            ['liquidity.usd', 999, false], ['liquidity.usd', 1000, true], ['volume.m5', 499, false], ['volume.m5', 500, true]];
    }

    public function test_audit_omits_oversized_or_structured_pair_addresses_and_raw_data(): void
    {
        foreach ([str_repeat('x', 10000), ['secret' => 'data'], null] as $value) {
            $pair = $this->pair();
            $pair['pairAddress'] = $value;
            $audit = $this->check([$pair]);
            $this->assertTrue($audit['passed']);
            $this->assertNull($audit['market']['pair_address']);
            $this->assertLessThan(1000, strlen(json_encode($audit)));
            $this->assertArrayNotHasKey('raw', $audit['market']);
        }
    }

    #[DataProvider('outages')]
    public function test_real_dexscreener_outage_is_unavailable_evidence(bool $timeout): void
    {
        Http::fake(['api.dexscreener.com/*' => $timeout ? Http::failedConnection() : Http::response([], 500)]);
        $audit = app(EthereumOpportunityRevalidationService::class)->check(new TradeOpportunity([
            'chain' => 'ethereum', 'address' => self::TOKEN, 'scanner' => 'momentum',
        ]));
        $this->assertFalse($audit['passed']);
        $this->assertSame('unavailable', $audit['failure_class']);
    }

    public static function outages(): array
    {
        return [[true], [false]];
    }

    private function pair(): array
    {
        return ['chainId' => 'ethereum', 'baseToken' => ['address' => self::TOKEN], 'marketCap' => 10000,
            'liquidity' => ['usd' => 10000], 'volume' => ['m5' => 10000], 'pairAddress' => '0x'.str_repeat('3', 40)];
    }

    private function check(array $pairs): array
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.dexscreener.com/*' => Http::response($pairs)]);

        return app(EthereumOpportunityRevalidationService::class)->check(new TradeOpportunity([
            'chain' => 'ethereum', 'address' => self::TOKEN, 'scanner' => 'momentum',
        ]));
    }
}
