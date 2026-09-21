<?php

namespace Tests\Feature;

use App\Chain;
use App\Models\ConnectedWallet;
use App\Models\SolanaSwapAttempt;
use App\Models\User;
use App\Services\JupiterSwapOrderService;
use App\Services\SolanaService;
use App\Services\SolanaSwapQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SolanaSwapOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_prepared_order_persists_its_recent_blockhash(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $address = '6Z7KvfSvatJqaj5kKB53TzZDxMJB8w3e2k2qz6qfYCvP';

        $wallet = ConnectedWallet::query()->create([
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

        $this->mock(SolanaService::class)
            ->shouldReceive('getBalanceLamports')
            ->once()
            ->with($address)
            ->andReturn(10_000_000);

        $transaction = base64_encode('prepared-transaction');
        $blockhash = '11111111111111111111111111111111';

        $this->mock(
            JupiterSwapOrderService::class,
            function ($mock) use (
                $address,
                $transaction,
                $blockhash
            ): void {
                $mock->shouldReceive('prepare')
                    ->once()
                    ->with(
                        $address,
                        'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v',
                        '1000000',
                        100
                    )
                    ->andReturn([
                        'transaction' => $transaction,
                        'request_id' => 'request-123',
                        'validation' => [
                            'message_hash' => str_repeat('a', 64),
                            'recent_blockhash' => $blockhash,
                        ],
                    ]);
            }
        );

        $response = $this->actingAs($user)
            ->postJson(route('wallets.solana.order'), [
                'input_mint' => SolanaSwapQuoteService::SOL_MINT,
                'output_mint' => 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v',
                'amount' => 1_000_000,
                'slippage_bps' => 100,
            ]);

        $response->assertOk();

        $attempt = SolanaSwapAttempt::query()->sole();

        $this->assertSame($user->id, $attempt->user_id);
        $this->assertSame($wallet->id, $attempt->connected_wallet_id);
        $this->assertSame('request-123', $attempt->request_id);
        $this->assertSame($blockhash, $attempt->recent_blockhash);
        $this->assertSame(
            str_repeat('a', 64),
            $attempt->message_hash
        );
        $this->assertSame(
            $transaction,
            $attempt->prepared_transaction
        );

        $response
            ->assertJsonPath('order.attempt_id', $attempt->id)
            ->assertJsonPath('order.transaction', $transaction);
    }

    public function test_history_is_user_scoped_and_returns_safe_formatted_transaction_details(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $otherUser = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $wallet = ConnectedWallet::query()->create([
            'user_id' => $user->id,
            'chain' => Chain::Solana,
            'address' => '6Z7KvfSvatJqaj5kKB53TzZDxMJB8w3e2k2qz6qfYCvP',
            'address_hash' => ConnectedWallet::addressHash(
                Chain::Solana,
                '6Z7KvfSvatJqaj5kKB53TzZDxMJB8w3e2k2qz6qfYCvP'
            ),
            'provider' => 'phantom',
            'verified_at' => now(),
            'last_connected_at' => now(),
        ]);

        $otherWallet = ConnectedWallet::query()->create([
            'user_id' => $otherUser->id,
            'chain' => Chain::Solana,
            'address' => '7Y7KvfSvatJqaj5kKB53TzZDxMJB8w3e2k2qz6qfYCvQ',
            'address_hash' => ConnectedWallet::addressHash(
                Chain::Solana,
                '7Y7KvfSvatJqaj5kKB53TzZDxMJB8w3e2k2qz6qfYCvQ'
            ),
            'provider' => 'phantom',
            'verified_at' => now(),
            'last_connected_at' => now(),
        ]);

        $older = SolanaSwapAttempt::query()->create([
            'user_id' => $user->id,
            'connected_wallet_id' => $wallet->id,
            'request_id' => 'request-older',
            'output_mint' => 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v',
            'input_amount_lamports' => 1_000_000,
            'slippage_bps' => 100,
            'message_hash' => str_repeat('a', 64),
            'recent_blockhash' => '11111111111111111111111111111111',
            'prepared_transaction' => 'secret-prepared-older',
            'status' => 'submitted',
            'transaction_signature' => str_repeat('1', 88),
            'submitted_at' => now()->subMinute(),
            'expires_at' => now()->addMinute(),
        ]);

        $newer = SolanaSwapAttempt::query()->create([
            'user_id' => $user->id,
            'connected_wallet_id' => $wallet->id,
            'request_id' => 'request-newer',
            'output_mint' => 'Es9vMFrzaCERmJfrF4H2FYD6tGhT5d1kRZq3h5YQkG6',
            'input_amount_lamports' => 1_234_567_890,
            'slippage_bps' => 100,
            'message_hash' => str_repeat('b', 64),
            'recent_blockhash' => '11111111111111111111111111111111',
            'prepared_transaction' => 'secret-prepared-newer',
            'status' => 'confirmed',
            'transaction_signature' => str_repeat('2', 88),
            'submitted_at' => now()->subSeconds(30),
            'confirmed_at' => now(),
            'network_fee_lamports' => 5000,
            'slot' => 123456789,
            'expires_at' => now()->addMinute(),
        ]);

        SolanaSwapAttempt::query()->create([
            'user_id' => $otherUser->id,
            'connected_wallet_id' => $otherWallet->id,
            'request_id' => 'request-foreign',
            'output_mint' => 'ForeignMint111111111111111111111111111111111',
            'input_amount_lamports' => 9_000_000_000,
            'slippage_bps' => 100,
            'message_hash' => str_repeat('c', 64),
            'recent_blockhash' => '11111111111111111111111111111111',
            'prepared_transaction' => 'secret-foreign-transaction',
            'status' => 'confirmed',
            'transaction_signature' => str_repeat('3', 88),
            'confirmed_at' => now(),
            'network_fee_lamports' => 9999,
            'slot' => 999999999,
            'expires_at' => now()->addMinute(),
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('wallets.solana.history'));

        $response
            ->assertOk()
            ->assertJsonCount(2, 'transactions')
            ->assertJsonPath('transactions.0.id', $newer->id)
            ->assertJsonPath('transactions.0.input_amount_lamports', '1234567890')
            ->assertJsonPath('transactions.0.input_amount_sol', '1.23456789')
            ->assertJsonPath('transactions.0.status', 'confirmed')
            ->assertJsonPath('transactions.0.transaction_signature', str_repeat('2', 88))
            ->assertJsonPath('transactions.0.network_fee_lamports', '5000')
            ->assertJsonPath('transactions.0.network_fee_sol', '0.000005')
            ->assertJsonPath('transactions.0.slot', 123456789)
            ->assertJsonPath('transactions.1.id', $older->id)
            ->assertJsonPath('transactions.1.input_amount_sol', '0.001')
            ->assertJsonPath('transactions.1.network_fee_sol', null);

        $payload = $response->json();

        $this->assertArrayNotHasKey(
            'prepared_transaction',
            $payload['transactions'][0]
        );
        $this->assertArrayNotHasKey(
            'message_hash',
            $payload['transactions'][0]
        );
        $this->assertArrayNotHasKey(
            'recent_blockhash',
            $payload['transactions'][0]
        );
        $this->assertArrayNotHasKey(
            'request_id',
            $payload['transactions'][0]
        );

        $this->assertFalse(
            collect($payload['transactions'])
                ->contains(fn (array $transaction): bool => $transaction['output_mint'] === 'ForeignMint111111111111111111111111111111111')
        );
    }
}
