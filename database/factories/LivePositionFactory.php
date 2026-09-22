<?php

namespace Database\Factories;

use App\Models\EthereumSwapAttempt;
use App\Models\LivePosition;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LivePosition> */
class LivePositionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['accounting_status' => 'pending'];
    }

    /** Explicit evidence is required; never fabricate a financial entry by default. */
    public function forEthereumAttempt(EthereumSwapAttempt $attempt): static
    {
        return $this->state(fn (): array => [
            'user_id' => $attempt->user_id, 'chain' => 'ethereum', 'network' => 'mainnet',
            'wallet_address' => $attempt->wallet_address, 'connected_wallet_id' => $attempt->connected_wallet_id,
            'trade_opportunity_id' => $attempt->trade_opportunity_id, 'ethereum_swap_attempt_id' => $attempt->id,
            'token_address' => $attempt->buy_token, 'entry_transaction_hash' => $attempt->transaction_hash,
            'entry_block_number' => $attempt->block_number, 'entry_confirmed_at' => $attempt->confirmed_at,
        ]);
    }
}
