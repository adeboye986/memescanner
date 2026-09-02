<?php

namespace Database\Factories;

use App\Models\TradeOpportunity;
use App\Models\TradeOpportunityEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TradeOpportunityEvent>
 */
class TradeOpportunityEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'trade_opportunity_id' => TradeOpportunity::factory(),
            'action' => 'ignored',
            'from_status' => 'qualified',
            'to_status' => 'ignored',
            'metadata' => ['source' => 'factory'],
        ];
    }
}
