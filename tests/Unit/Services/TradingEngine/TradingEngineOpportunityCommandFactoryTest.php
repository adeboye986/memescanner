<?php

namespace Tests\Unit\Services\TradingEngine;

use App\Chain;
use App\Models\TradeOpportunity;
use App\Models\User;
use App\Services\TradingEngine\TradingEngineOpportunityCommandFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TradingEngineOpportunityCommandFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_maps_solana_new_token_with_one_minute_birdeye_volume_and_canonical_decimals(): void
    {
        $opportunity = $this->opportunity([
            'scanner' => 'new-token',
            'price' => '0.000001230000000000',
            'market_cap' => '12000.5000',
            'liquidity' => '3000.0000',
            'volume' => '800.2500',
            'qualification_data' => [
                'discovery_market_cap' => 10000,
                'move_since_discovery_percent' => 20.005,
                'meta' => [
                    'entry_source' => 'birdeye_provisional',
                    'classification' => 'strong',
                ],
            ],
            'security_data' => [
                'status' => 'passed',
                'provider' => 'GoPlus',
                'passed' => true,
                'score' => 95,
                'risks' => [],
                'coverage' => 'GoPlus Solana token-security evaluation.',
            ],
        ]);

        $command = app(TradingEngineOpportunityCommandFactory::class)->make($opportunity);

        $this->assertSame('opportunity:record:laravel:'.$opportunity->id.':v1', $command['idempotency_key']);
        $this->assertSame('1m', $command['payload']['market_snapshot']['volume_usd']['window']);
        $this->assertSame('birdeye', $command['payload']['market_snapshot']['volume_usd']['provider']);
        $this->assertSame('0.00000123', $command['payload']['market_snapshot']['price_usd']['value']);
        $this->assertSame('12000.5', $command['payload']['market_snapshot']['market_cap_usd']['value']);
        $this->assertSame('20.005', $command['payload']['qualification']['move_since_discovery_percent']);
        $this->assertSame('goplus', $command['payload']['security']['provider']);
        $this->assertArrayNotHasKey('execution_mode', $command['payload']);
        $this->assertArrayNotHasKey('entry_mode', $command['payload']);
    }

    public function test_maps_solana_momentum_with_five_minute_dex_volume_and_non_final_holder_security(): void
    {
        $opportunity = $this->opportunity([
            'scanner' => 'momentum',
            'pair_address' => '9xQeWvG816bUx9EPjHmaT23yvVMV84LkQg21qZC5YQ9',
            'qualification_data' => [
                'discovery_market_cap' => 10000,
                'move_since_discovery_percent' => -2.5,
                'meta' => ['dex' => 'raydium', 'source' => 'momentum_fast_paper'],
            ],
            'security_data' => [
                'status' => 'passed',
                'provider' => 'Solana RPC holder analysis',
                'passed' => true,
                'score' => 80,
                'risks' => [],
                'coverage' => 'Solana holder concentration at qualification.',
                'holder_concentration' => [
                    'largest_holder_percentage' => 12.5,
                    'top_5_percentage' => 30,
                    'top_10_percentage' => 45.75,
                    'risk_level' => 'low',
                ],
            ],
        ]);

        $payload = app(TradingEngineOpportunityCommandFactory::class)->make($opportunity)['payload'];

        $this->assertSame('5m', $payload['market_snapshot']['volume_usd']['window']);
        $this->assertSame('dexscreener', $payload['market_snapshot']['volume_usd']['provider']);
        $this->assertSame('solana_rpc_holder_analysis', $payload['security']['provider']);
        $this->assertSame('12.5', $payload['security']['holder_concentration']['largest_holder_percent']);
        $this->assertArrayNotHasKey('final', $payload['security']);
        $this->assertSame('-2.5', $payload['qualification']['move_since_discovery_percent']);
    }

    public function test_maps_ethereum_volume_as_five_minute_and_allows_security_to_be_absent(): void
    {
        $opportunity = $this->opportunity([
            'chain' => Chain::Ethereum,
            'address' => '0x1111111111111111111111111111111111111111',
            'scanner' => 'new-token',
            'security_data' => null,
            'qualification_data' => [
                'discovery_market_cap' => 25000,
                'move_since_discovery_percent' => 0,
                'meta' => ['dex' => 'uniswap'],
            ],
        ]);

        $payload = app(TradingEngineOpportunityCommandFactory::class)->make($opportunity)['payload'];

        $this->assertSame('eip155:1', $payload['network']['id']);
        $this->assertSame('5m', $payload['market_snapshot']['volume_usd']['window']);
        $this->assertSame('dexscreener', $payload['market_snapshot']['volume_usd']['provider']);
        $this->assertArrayNotHasKey('security', $payload);
    }

    /** @param array<string, mixed> $overrides */
    private function opportunity(array $overrides): TradeOpportunity
    {
        $user = User::factory()->create();

        return TradeOpportunity::factory()->for($user)->create([
            'chain' => Chain::Solana,
            'address' => 'So11111111111111111111111111111111111111112',
            'scanner' => 'new-token',
            'discovery_key' => str_repeat('a', 64),
            'price' => '0.00000125',
            'market_cap' => '12000',
            'liquidity' => '3000',
            'volume' => '1500',
            'qualified_at' => '2026-09-28 12:00:00',
            ...$overrides,
        ]);
    }
}
