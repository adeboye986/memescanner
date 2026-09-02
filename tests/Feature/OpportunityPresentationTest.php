<?php

namespace Tests\Feature;

use App\Models\PaperPosition;
use App\Models\TradeOpportunity;
use App\Models\User;
use Tests\Concerns\RefreshesPaperTradingDatabase;
use Tests\TestCase;

class OpportunityPresentationTest extends TestCase
{
    use RefreshesPaperTradingDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refreshPaperTradingDatabase();
        $this->withoutVite();
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    public function test_ethereum_page_is_honest_and_omits_solana_only_debug_metadata(): void
    {
        $opportunity = TradeOpportunity::factory()->create([
            'chain' => 'ethereum',
            'volume' => 1250,
            'qualification_data' => ['meta' => [
                'debug_marker' => 'RAW_META_MUST_NOT_RENDER',
                'unavailable_security_checks' => ['Birdeye Solana overview', 'GoPlus Solana token-security evaluation', 'Pump.fun activity analysis'],
            ]],
            'security_data' => [
                'status' => 'unavailable',
                'coverage' => 'No Ethereum token-security provider is configured.',
                'market_validation' => ['provider' => 'DexScreener', 'requested_token_is_base' => true, 'pair_available' => true],
            ],
        ]);

        $this->actingAs($this->admin)->get(route('opportunities.show', $opportunity))
            ->assertSuccessful()
            ->assertSee('Ethereum Security Coverage')
            ->assertSee('No Ethereum token-security provider is configured.')
            ->assertSee('DexScreener')
            ->assertSee('$1,250.00')
            ->assertDontSee('RAW_META_MUST_NOT_RENDER')
            ->assertDontSee('Birdeye Solana overview')
            ->assertDontSee('GoPlus Solana token-security evaluation')
            ->assertDontSee('Pump.fun activity analysis');
    }

    public function test_solana_page_renders_only_structured_applicable_security_information(): void
    {
        $opportunity = TradeOpportunity::factory()->create([
            'chain' => 'solana',
            'security_data' => [
                'status' => 'passed', 'provider' => 'GoPlus', 'passed' => true, 'score' => 100,
                'coverage' => 'GoPlus Solana token-security evaluation.', 'risks' => [],
            ],
        ]);

        $this->actingAs($this->admin)->get(route('opportunities.show', $opportunity))
            ->assertSee('Security Status')
            ->assertSee('Passed')
            ->assertSee('GoPlus Solana token-security evaluation.')
            ->assertDontSee('No snapshot data stored');
    }

    public function test_missing_volume_and_security_remain_explicitly_unavailable(): void
    {
        $opportunity = TradeOpportunity::factory()->create(['volume' => null, 'security_data' => null]);

        $this->actingAs($this->admin)->get(route('opportunities.show', $opportunity))
            ->assertSee('Volume was not captured by the scanner')
            ->assertSee('No Solana security snapshot was stored')
            ->assertSee('unavailable');
    }

    public function test_executed_opportunity_presents_position_result_and_link(): void
    {
        $position = PaperPosition::query()->create([
            'chain' => 'solana', 'address' => 'position-token', 'symbol' => 'OPEN',
            'entry_market_cap' => 10000, 'last_market_cap' => 10000, 'peak_market_cap' => 10000,
            'status' => 'open', 'entry_at' => now(), 'initial_investment_sol' => 0.1,
            'remaining_investment_sol' => 0.1, 'remaining_fraction' => 1,
        ]);
        $opportunity = TradeOpportunity::factory()->create([
            'status' => 'executed', 'execution_mode' => 'paper', 'entry_mode' => 'auto', 'paper_position_id' => $position->id,
        ]);

        $this->actingAs($this->admin)->get(route('opportunities.show', $opportunity))
            ->assertSee('Paper Trade Opened')
            ->assertSee("Position #{$position->id}")
            ->assertSee('Execution mode:')
            ->assertSee('Entry policy:')
            ->assertSee('View Position');
    }
}
