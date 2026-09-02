<?php

namespace Database\Factories;

use App\Chain;
use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Enums\TradeOpportunityStatus;
use App\Models\TradeOpportunity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TradeOpportunity>
 */
class TradeOpportunityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chain' => Chain::Solana,
            'address' => fake()->unique()->sha256(),
            'symbol' => strtoupper(fake()->lexify('????')),
            'name' => fake()->words(2, true),
            'scanner' => 'factory',
            'status' => TradeOpportunityStatus::Qualified,
            'execution_mode' => ExecutionMode::Paper,
            'entry_mode' => EntryMode::Signal,
            'price' => fake()->randomFloat(8, 0.00000001, 0.01),
            'market_cap' => fake()->randomFloat(2, 1000, 1000000),
            'liquidity' => fake()->randomFloat(2, 1000, 100000),
            'volume' => fake()->randomFloat(2, 1000, 100000),
            'qualification_data' => [],
            'security_data' => [],
            'qualified_at' => now(),
        ];
    }
}
