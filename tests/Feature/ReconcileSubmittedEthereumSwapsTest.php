<?php

namespace Tests\Feature;

use App\Models\ConnectedWallet;
use App\Models\EthereumSwapAttempt;
use App\Models\User;
use App\Services\EthereumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ReconcileSubmittedEthereumSwapsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reconciles_submitted_ethereum_swap_attempts_from_receipts(): void
    {
        $user = User::factory()->create();

        $address = '0x1111111111111111111111111111111111111111';

        $wallet = ConnectedWallet::create([
            'user_id' => $user->id,
            'chain' => 'ethereum',
            'address' => $address,
            'address_hash' => hash('sha256', strtolower($address)),
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
                    'gas_used' => '21000',
                    'effective_gas_price_wei' => '1000000000',
                    'actual_network_fee_wei' => '21000000000000',
                ]);

            $mock->shouldReceive('getTransactionReceipt')
                ->once()
                ->with($failedHash)
                ->andReturn([
                    'transaction_hash' => $failedHash,
                    'succeeded' => false,
                    'block_number' => '101',
                    'gas_used' => '30000',
                    'effective_gas_price_wei' => '2000000000',
                    'actual_network_fee_wei' => '60000000000000',
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

        $this->assertSame('100', $confirmed->block_number);
        $this->assertSame('21000', $confirmed->gas_used);
        $this->assertSame('1000000000', $confirmed->effective_gas_price_wei);
        $this->assertSame('21000000000000', $confirmed->actual_network_fee_wei);

        $this->assertSame('failed', $failed->status);
        $this->assertNotNull($failed->failed_at);
        $this->assertSame(
            'Ethereum transaction reverted on-chain.',
            $failed->failure_reason,
        );

        $this->assertSame('101', $failed->block_number);
        $this->assertSame('30000', $failed->gas_used);
        $this->assertSame('2000000000', $failed->effective_gas_price_wei);
        $this->assertSame('60000000000000', $failed->actual_network_fee_wei);

        $this->assertSame('submitted', $pending->status);
        $this->assertNull($pending->confirmed_at);
        $this->assertNull($pending->failed_at);

        $this->assertSame('cancelled', $cancelled->status);
    }

    public function test_rpc_failure_leaves_submitted_swap_unchanged(): void
    {
        $user = User::factory()->create();

        $address = '0x1111111111111111111111111111111111111111';

        $wallet = ConnectedWallet::create([
            'user_id' => $user->id,
            'chain' => 'ethereum',
            'address' => $address,
            'address_hash' => hash('sha256', strtolower($address)),
        ]);

        $attempt = EthereumSwapAttempt::create([
            'user_id' => $user->id,
            'connected_wallet_id' => $wallet->id,
            'buy_token' => '0x2222222222222222222222222222222222222222',
            'sell_amount_wei' => '100000000000000',
            'slippage_bps' => 100,
            'transaction_payload' => [],
            'status' => 'submitted',
            'transaction_hash' => '0x'.str_repeat('a', 64),
            'expires_at' => now()->addMinute(),
            'submitted_at' => now(),
        ]);

        $ethereum = $this->mock(EthereumService::class);

        $ethereum->shouldReceive('getTransactionReceipt')
            ->once()
            ->with($attempt->transaction_hash)
            ->andThrow(new RuntimeException('Ethereum RPC is temporarily unavailable.'));

        $this->artisan('ethereum:reconcile-submitted-swaps')
            ->assertSuccessful();

        $attempt->refresh();

        $this->assertSame('submitted', $attempt->status);
        $this->assertNull($attempt->confirmed_at);
        $this->assertNull($attempt->failed_at);
        $this->assertNull($attempt->failure_reason);
    }

    public function test_receipt_hash_mismatch_leaves_submitted_swap_unchanged(): void
    {
        $user = User::factory()->create();

        $address = '0x1111111111111111111111111111111111111111';

        $wallet = ConnectedWallet::create([
            'user_id' => $user->id,
            'chain' => 'ethereum',
            'address' => $address,
            'address_hash' => hash('sha256', strtolower($address)),
        ]);

        $attempt = EthereumSwapAttempt::create([
            'user_id' => $user->id,
            'connected_wallet_id' => $wallet->id,
            'buy_token' => '0x2222222222222222222222222222222222222222',
            'sell_amount_wei' => '100000000000000',
            'slippage_bps' => 100,
            'transaction_payload' => [],
            'status' => 'submitted',
            'transaction_hash' => '0x'.str_repeat('a', 64),
            'expires_at' => now()->addMinute(),
            'submitted_at' => now(),
        ]);

        $ethereum = $this->mock(EthereumService::class);

        $ethereum->shouldReceive('getTransactionReceipt')
            ->once()
            ->with($attempt->transaction_hash)
            ->andReturn([
                'transaction_hash' => '0x'.str_repeat('b', 64),
                'succeeded' => true,
                'block_number' => '100',
            ]);

        $this->artisan('ethereum:reconcile-submitted-swaps')
            ->assertSuccessful();

        $attempt->refresh();

        $this->assertSame('submitted', $attempt->status);
        $this->assertNull($attempt->confirmed_at);
        $this->assertNull($attempt->failed_at);
        $this->assertNull($attempt->failure_reason);
    }
}
