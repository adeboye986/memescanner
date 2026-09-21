<?php

namespace Tests\Feature;

use App\Chain;
use App\Models\ConnectedWallet;
use App\Models\SolanaSwapAttempt;
use App\Models\User;
use App\Services\SolanaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ReconcileSubmittedSolanaSwapsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reconciles_confirmed_failed_and_pending_submitted_swaps(): void
    {
        $user = User::factory()->create();
        $wallet = $this->wallet($user);

        $confirmed = $this->attempt(
            $user,
            $wallet,
            'confirmed-signature'
        );

        $failed = $this->attempt(
            $user,
            $wallet,
            'failed-signature'
        );

        $pending = $this->attempt(
            $user,
            $wallet,
            'pending-signature'
        );

        $solana = $this->mock(SolanaService::class);

        $solana->shouldReceive('getTransactionReceipt')
            ->once()
            ->with('confirmed-signature')
            ->andReturn([
                'succeeded' => true,
                'slot' => 123456789,
                'network_fee_lamports' => 5000,
                'error' => null,
            ]);

        $solana->shouldReceive('getTransactionReceipt')
            ->once()
            ->with('failed-signature')
            ->andReturn([
                'succeeded' => false,
                'slot' => 123456790,
                'network_fee_lamports' => 7000,
                'error' => [
                    'InstructionError' => [2, 'Custom'],
                ],
            ]);

        $solana->shouldReceive('getTransactionReceipt')
            ->once()
            ->with('pending-signature')
            ->andReturn(null);

        $this->artisan('solana:reconcile-submitted-swaps')
            ->expectsOutput(
                'Confirmed 1, failed 1, pending 1, RPC errors 0.'
            )
            ->assertSuccessful();

        $confirmed->refresh();
        $failed->refresh();
        $pending->refresh();

        $this->assertSame('confirmed', $confirmed->status);
        $this->assertNotNull($confirmed->confirmed_at);
        $this->assertNull($confirmed->failed_at);
        $this->assertNull($confirmed->failure_reason);
        $this->assertSame(5000, (int) $confirmed->network_fee_lamports);
        $this->assertSame(123456789, (int) $confirmed->slot);

        $this->assertSame('failed', $failed->status);
        $this->assertNotNull($failed->failed_at);
        $this->assertNull($failed->confirmed_at);
        $this->assertSame(
            'Solana transaction failed on-chain.',
            $failed->failure_reason
        );
        $this->assertSame(7000, (int) $failed->network_fee_lamports);
        $this->assertSame(123456790, (int) $failed->slot);

        $this->assertSame('submitted', $pending->status);
        $this->assertNull($pending->confirmed_at);
        $this->assertNull($pending->failed_at);
        $this->assertNull($pending->network_fee_lamports);
        $this->assertNull($pending->slot);
    }

    public function test_it_recovers_a_submitting_swap_when_the_verified_transaction_is_found_on_chain(): void
    {
        $user = User::factory()->create();
        $wallet = $this->wallet($user);

        $attempt = $this->attempt(
            $user,
            $wallet,
            'recoverable-signature',
            'submitting'
        );

        $this->mock(SolanaService::class)
            ->shouldReceive('getTransactionReceipt')
            ->once()
            ->with('recoverable-signature')
            ->andReturn([
                'succeeded' => true,
                'slot' => 123456791,
                'network_fee_lamports' => 6000,
                'error' => null,
            ]);

        $this->artisan('solana:reconcile-submitted-swaps')
            ->expectsOutput(
                'Confirmed 1, failed 0, pending 0, RPC errors 0.'
            )
            ->assertSuccessful();

        $attempt->refresh();

        $this->assertSame('confirmed', $attempt->status);
        $this->assertNotNull($attempt->confirmed_at);
        $this->assertNull($attempt->failed_at);
        $this->assertNull($attempt->failure_reason);
        $this->assertSame(6000, (int) $attempt->network_fee_lamports);
        $this->assertSame(123456791, (int) $attempt->slot);
    }

    public function test_rpc_failure_leaves_submitted_swap_unchanged(): void
    {
        $user = User::factory()->create();
        $wallet = $this->wallet($user);

        $attempt = $this->attempt(
            $user,
            $wallet,
            'rpc-error-signature'
        );

        $this->mock(SolanaService::class)
            ->shouldReceive('getTransactionReceipt')
            ->once()
            ->with('rpc-error-signature')
            ->andThrow(new RuntimeException('RPC unavailable.'));

        $this->artisan('solana:reconcile-submitted-swaps')
            ->expectsOutput(
                'Confirmed 0, failed 0, pending 0, RPC errors 1.'
            )
            ->assertSuccessful();

        $attempt->refresh();

        $this->assertSame('submitted', $attempt->status);
        $this->assertNull($attempt->confirmed_at);
        $this->assertNull($attempt->failed_at);
        $this->assertNull($attempt->failure_reason);
        $this->assertNull($attempt->network_fee_lamports);
        $this->assertNull($attempt->slot);
    }

    private function wallet(User $user): ConnectedWallet
    {
        $address = '6Z7KvfSvatJqaj5kKB53TzZDxMJB8w3e2k2qz6qfYCvP';

        return ConnectedWallet::query()->create([
            'user_id' => $user->id,
            'chain' => Chain::Solana,
            'address' => $address,
            'address_hash' => ConnectedWallet::addressHash(
                Chain::Solana,
                $address
            ),
            'provider' => 'phantom',
            'verified_at' => now(),
            'last_connected_at' => now(),
        ]);
    }

    private function attempt(
        User $user,
        ConnectedWallet $wallet,
        string $signature,
        string $status = 'submitted'
    ): SolanaSwapAttempt {
        return SolanaSwapAttempt::query()->create([
            'user_id' => $user->id,
            'connected_wallet_id' => $wallet->id,
            'request_id' => 'request-'.$signature,
            'output_mint' => 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v',
            'input_amount_lamports' => 1_000_000,
            'slippage_bps' => 100,
            'message_hash' => str_repeat('a', 64),
            'prepared_transaction' => base64_encode('prepared'),
            'status' => $status,
            'transaction_signature' => $signature,
            'expires_at' => now()->addMinute(),
            'submitted_at' => now(),
        ]);
    }
}
