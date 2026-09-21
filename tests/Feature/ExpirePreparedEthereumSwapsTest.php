<?php

namespace Tests\Feature;

use App\Models\ConnectedWallet;
use App\Models\EthereumSwapAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpirePreparedEthereumSwapsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_expires_only_stale_prepared_ethereum_swap_attempts(): void
    {
        $user = User::factory()->create();

        $address = '0x1111111111111111111111111111111111111111';

        $wallet = ConnectedWallet::query()->create([
            'user_id' => $user->id,
            'chain' => 'ethereum',
            'address' => $address,
            'address_hash' => ConnectedWallet::addressHash('ethereum', $address),
            'provider' => 'test',
            'verified_at' => now(),
        ]);

        $makeAttempt = function (string $status, $expiresAt) use ($user, $wallet): EthereumSwapAttempt {
            return EthereumSwapAttempt::query()->create([
                'user_id' => $user->id,
                'connected_wallet_id' => $wallet->id,
                'buy_token' => '0xa0b86991c6218b36c1d19d4a2e9eb0ce3606eb48',
                'sell_amount_wei' => '100000000000000',
                'slippage_bps' => 100,
                'quote_id' => 'test-quote-'.uniqid(),
                'transaction_payload' => [
                    'from' => $wallet->address,
                    'to' => '0x2222222222222222222222222222222222222222',
                    'data' => '0x',
                    'value' => '100000000000000',
                    'gas' => '21000',
                    'gasPrice' => '1000000000',
                ],
                'status' => $status,
                'expires_at' => $expiresAt,
            ]);
        };

        $expiredPrepared = $makeAttempt('prepared', now()->subMinute());
        $freshPrepared = $makeAttempt('prepared', now()->addMinute());
        $submitted = $makeAttempt('submitted', now()->subMinute());
        $cancelled = $makeAttempt('cancelled', now()->subMinute());

        $this->artisan('ethereum:expire-prepared-swaps')
            ->expectsOutput('Expired 1 prepared Ethereum swap attempt(s).')
            ->assertSuccessful();

        $this->assertSame('expired', $expiredPrepared->fresh()->status);
        $this->assertSame('prepared', $freshPrepared->fresh()->status);
        $this->assertSame('submitted', $submitted->fresh()->status);
        $this->assertSame('cancelled', $cancelled->fresh()->status);
    }
}
