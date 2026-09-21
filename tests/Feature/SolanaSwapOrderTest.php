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
}
