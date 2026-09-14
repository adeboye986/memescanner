<?php

namespace Tests\Feature;

use App\Models\ConnectedWallet;
use App\Models\EthereumSwapAttempt;
use App\Models\User;
use App\Services\EthereumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconcileSubmittedEthereumSwapsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reconciles_submitted_ethereum_swap_attempts_from_receipts(): void
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

        $makeAttempt = function (string $status, string $hash) use ($user, $wallet): EthereumSwapAttempt {
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
                'transaction_hash' => $hash,
                'expires_at' => now()->subMinute(),
                'submitted_at' => $status === 'submitted' ? now()->subMinute() : null,
            ]);
        };

        $confirmedHash = '0x'.str_repeat('a', 64);
        $failedHash = '0x'.str_repeat('b', 64);
        $pendingHash = '0x'.str_repeat('c', 64);
        $cancelledHash = '0x'.str_repeat('d', 64);

        $confirmed = $makeAttempt('submitted', $confirmedHash);
        $failed = $makeAttempt('submitted', $failedHash);
        $pending = $makeAttempt('submitted', $pendingHash);
        $cancelled = $makeAttempt('cancelled', $cancelledHash);

        $this->mock(EthereumService::class, function ($mock) use (
            $confirmedHash,
            $failedHash,
            $pendingHash
        ): void {
            $mock->shouldReceive('getTransactionReceipt')
                ->once()
                ->with($confirmedHash)
                ->andReturn([
                    'transaction_hash' => $confirmedHash,
                    'succeeded' => true,
                    'block_number' => '100',
                ]);

            $mock->shouldReceive('getTransactionReceipt')
                ->once()
                ->with($failedHash)
                ->andReturn([
                    'transaction_hash' => $failedHash,
                    'succeeded' => false,
                    'block_number' => '101',
                ]);

            $mock->shouldReceive('getTransactionReceipt')
                ->once()
                ->with($pendingHash)
                ->andReturn(null);
        });

        $this->artisan('ethereum:reconcile-submitted-swaps')
            ->expectsOutput(
                'Checked 3 submitted Ethereum swap attempt(s): 1 confirmed, 1 failed.'
            )
            ->assertSuccessful();

        $confirmed->refresh();
        $failed->refresh();
        $pending->refresh();
        $cancelled->refresh();

        $this->assertSame('confirmed', $confirmed->status);
        $this->assertNotNull($confirmed->confirmed_at);
        $this->assertNull($confirmed->failed_at);
        $this->assertNull($confirmed->failure_reason);

        $this->assertSame('failed', $failed->status);
        $this->assertNotNull($failed->failed_at);
        $this->assertSame(
            'Ethereum transaction reverted on-chain.',
            $failed->failure_reason,
        );

        $this->assertSame('submitted', $pending->status);
        $this->assertNull($pending->confirmed_at);
        $this->assertNull($pending->failed_at);

        $this->assertSame('cancelled', $cancelled->status);
    }
}
