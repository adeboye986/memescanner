<?php

namespace Tests\Unit;

use App\Services\ApplicationSettingsService;
use App\Services\SolanaService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SolanaServiceTest extends TestCase
{
    public function test_it_reads_native_balance_in_lamports(): void
    {
        $rpcUrl = 'https://solana-rpc.example.test';
        $address = '11111111111111111111111111111111';

        $settings = $this->mock(ApplicationSettingsService::class);
        $settings->shouldReceive('getSecret')
            ->once()
            ->with('blockchain.solana_rpc_url')
            ->andReturn($rpcUrl);

        Http::fake([
            $rpcUrl => Http::response([
                'jsonrpc' => '2.0',
                'result' => [
                    'context' => ['slot' => 123456],
                    'value' => 1_234_567_890,
                ],
                'id' => 1,
            ]),
        ]);

        $balance = app(SolanaService::class)
            ->getBalanceLamports($address);

        $this->assertSame(1_234_567_890, $balance);

        Http::assertSent(function ($request) use ($rpcUrl, $address): bool {
            return $request->url() === $rpcUrl
                && $request['jsonrpc'] === '2.0'
                && $request['method'] === 'getBalance'
                && $request['params'] === [
                    $address,
                    ['commitment' => 'confirmed'],
                ];
        });
    }

    public function test_zero_balance_is_valid(): void
    {
        $rpcUrl = 'https://solana-rpc.example.test';

        $settings = $this->mock(ApplicationSettingsService::class);
        $settings->shouldReceive('getSecret')
            ->once()
            ->andReturn($rpcUrl);

        Http::fake([
            $rpcUrl => Http::response([
                'jsonrpc' => '2.0',
                'result' => ['value' => 0],
                'id' => 1,
            ]),
        ]);

        $this->assertSame(
            0,
            app(SolanaService::class)
                ->getBalanceLamports('11111111111111111111111111111111')
        );
    }

    public function test_invalid_balance_response_is_rejected(): void
    {
        $rpcUrl = 'https://solana-rpc.example.test';

        $settings = $this->mock(ApplicationSettingsService::class);
        $settings->shouldReceive('getSecret')
            ->once()
            ->andReturn($rpcUrl);

        Http::fake([
            $rpcUrl => Http::response([
                'jsonrpc' => '2.0',
                'result' => ['value' => 'not-a-balance'],
                'id' => 1,
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Solana RPC returned an invalid wallet balance.'
        );

        app(SolanaService::class)
            ->getBalanceLamports('11111111111111111111111111111111');
    }

    public function test_negative_balance_is_rejected(): void
    {
        $rpcUrl = 'https://solana-rpc.example.test';

        $settings = $this->mock(ApplicationSettingsService::class);
        $settings->shouldReceive('getSecret')
            ->once()
            ->andReturn($rpcUrl);

        Http::fake([
            $rpcUrl => Http::response([
                'jsonrpc' => '2.0',
                'result' => ['value' => -1],
                'id' => 1,
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Solana RPC returned an invalid wallet balance.'
        );

        app(SolanaService::class)
            ->getBalanceLamports('11111111111111111111111111111111');
    }

    public function test_it_reads_successful_transaction_receipt(): void
    {
        $rpcUrl = 'https://solana-rpc.example.test';
        $signature = 'test-signature';

        $settings = $this->mock(ApplicationSettingsService::class);
        $settings->shouldReceive('getSecret')
            ->once()
            ->andReturn($rpcUrl);

        Http::fake([
            $rpcUrl => Http::response([
                'jsonrpc' => '2.0',
                'result' => [
                    'slot' => 123456789,
                    'meta' => [
                        'err' => null,
                        'fee' => 5000,
                    ],
                    'transaction' => [],
                ],
                'id' => 1,
            ]),
        ]);

        $receipt = app(SolanaService::class)
            ->getTransactionReceipt($signature);

        $this->assertSame([
            'succeeded' => true,
            'slot' => 123456789,
            'network_fee_lamports' => 5000,
            'error' => null,
        ], $receipt);

        Http::assertSent(fn ($request): bool => $request['method'] === 'getTransaction'
            && $request['params'] === [
                $signature,
                [
                    'encoding' => 'jsonParsed',
                    'commitment' => 'confirmed',
                    'maxSupportedTransactionVersion' => 0,
                ],
            ]);
    }

    public function test_it_reads_failed_transaction_receipt(): void
    {
        $rpcUrl = 'https://solana-rpc.example.test';

        $settings = $this->mock(ApplicationSettingsService::class);
        $settings->shouldReceive('getSecret')
            ->once()
            ->andReturn($rpcUrl);

        $error = [
            'InstructionError' => [2, 'Custom'],
        ];

        Http::fake([
            $rpcUrl => Http::response([
                'jsonrpc' => '2.0',
                'result' => [
                    'slot' => 123456790,
                    'meta' => [
                        'err' => $error,
                        'fee' => 7000,
                    ],
                    'transaction' => [],
                ],
                'id' => 1,
            ]),
        ]);

        $receipt = app(SolanaService::class)
            ->getTransactionReceipt('failed-signature');

        $this->assertFalse($receipt['succeeded']);
        $this->assertSame(123456790, $receipt['slot']);
        $this->assertSame(7000, $receipt['network_fee_lamports']);
        $this->assertSame($error, $receipt['error']);
    }

    public function test_missing_transaction_receipt_is_pending(): void
    {
        $rpcUrl = 'https://solana-rpc.example.test';

        $settings = $this->mock(ApplicationSettingsService::class);
        $settings->shouldReceive('getSecret')
            ->once()
            ->andReturn($rpcUrl);

        Http::fake([
            $rpcUrl => Http::response([
                'jsonrpc' => '2.0',
                'result' => null,
                'id' => 1,
            ]),
        ]);

        $this->assertNull(
            app(SolanaService::class)
                ->getTransactionReceipt('pending-signature')
        );
    }

    public function test_it_retrieves_the_confirmed_signed_transaction_as_base64(): void
    {
        $rpcUrl = 'https://solana-rpc.example.test';
        $signature = str_repeat('3', 88);
        $encoded = base64_encode('signed-versioned-transaction');

        $settings = $this->mock(ApplicationSettingsService::class);
        $settings->shouldReceive('getSecret')->once()->andReturn($rpcUrl);
        Http::fake([
            $rpcUrl => Http::response([
                'jsonrpc' => '2.0',
                'result' => ['transaction' => [$encoded, 'base64']],
                'id' => 1,
            ]),
        ]);

        $this->assertSame($encoded, app(SolanaService::class)->getTransactionBase64($signature));

        Http::assertSent(fn ($request): bool => $request['method'] === 'getTransaction'
            && $request['params'] === [
                $signature,
                [
                    'encoding' => 'base64',
                    'commitment' => 'confirmed',
                    'maxSupportedTransactionVersion' => 0,
                ],
            ]);
    }

    public function test_opportunity_receipt_aggregates_exact_wallet_token_balance_delta(): void
    {
        $rpcUrl = 'https://solana-rpc.example.test';
        $wallet = '6Z7KvfSvatJqaj5kKB53TzZDxMJB8w3e2k2qz6qfYCvP';
        $mint = 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v';

        $settings = $this->mock(ApplicationSettingsService::class);
        $settings->shouldReceive('getSecret')->once()->andReturn($rpcUrl);
        Http::fake([
            $rpcUrl => Http::response([
                'jsonrpc' => '2.0',
                'result' => [
                    'slot' => 123456789,
                    'meta' => [
                        'err' => null,
                        'fee' => 5000,
                        'preTokenBalances' => [
                            $this->tokenBalance($wallet, $mint, '900719925474099300000', 9),
                            $this->tokenBalance('another-wallet', $mint, '999999999', 9),
                        ],
                        'postTokenBalances' => [
                            $this->tokenBalance($wallet, $mint, '900719925474099300005', 9),
                            $this->tokenBalance($wallet, $mint, '7', 9),
                            $this->tokenBalance($wallet, 'another-mint', '999999999', 9),
                        ],
                    ],
                    'transaction' => [],
                ],
                'id' => 1,
            ]),
        ]);

        $receipt = app(SolanaService::class)
            ->getOpportunityTransactionReceipt(str_repeat('4', 88), $wallet, $mint);

        $this->assertSame('12', $receipt['acquired_raw_amount']);
        $this->assertSame(9, $receipt['token_decimals']);
        $this->assertSame(123456789, $receipt['slot']);
        $this->assertTrue($receipt['succeeded']);
    }

    /** @return array{owner: string, mint: string, uiTokenAmount: array{amount: string, decimals: int}} */
    private function tokenBalance(string $owner, string $mint, string $amount, int $decimals): array
    {
        return [
            'owner' => $owner,
            'mint' => $mint,
            'uiTokenAmount' => ['amount' => $amount, 'decimals' => $decimals],
        ];
    }

    public function test_it_reports_a_valid_recent_blockhash(): void
    {
        $rpcUrl = 'https://solana-rpc.example.test';
        $blockhash = '11111111111111111111111111111111';

        $settings = $this->mock(ApplicationSettingsService::class);
        $settings->shouldReceive('getSecret')
            ->once()
            ->andReturn($rpcUrl);

        Http::fake([
            $rpcUrl => Http::response([
                'jsonrpc' => '2.0',
                'result' => [
                    'context' => ['slot' => 123456],
                    'value' => true,
                ],
                'id' => 1,
            ]),
        ]);

        $this->assertTrue(
            app(SolanaService::class)->isBlockhashValid($blockhash)
        );

        Http::assertSent(fn ($request): bool => $request['method'] === 'isBlockhashValid'
            && $request['params'] === [
                $blockhash,
                ['commitment' => 'confirmed'],
            ]);
    }

    public function test_it_reports_an_expired_recent_blockhash(): void
    {
        $rpcUrl = 'https://solana-rpc.example.test';

        $settings = $this->mock(ApplicationSettingsService::class);
        $settings->shouldReceive('getSecret')
            ->once()
            ->andReturn($rpcUrl);

        Http::fake([
            $rpcUrl => Http::response([
                'jsonrpc' => '2.0',
                'result' => [
                    'context' => ['slot' => 123457],
                    'value' => false,
                ],
                'id' => 1,
            ]),
        ]);

        $this->assertFalse(
            app(SolanaService::class)
                ->isBlockhashValid('11111111111111111111111111111111')
        );
    }

    public function test_invalid_blockhash_validity_response_is_rejected(): void
    {
        $rpcUrl = 'https://solana-rpc.example.test';

        $settings = $this->mock(ApplicationSettingsService::class);
        $settings->shouldReceive('getSecret')
            ->once()
            ->andReturn($rpcUrl);

        Http::fake([
            $rpcUrl => Http::response([
                'jsonrpc' => '2.0',
                'result' => ['value' => 'false'],
                'id' => 1,
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Solana RPC returned an invalid blockhash validity response.'
        );

        app(SolanaService::class)
            ->isBlockhashValid('11111111111111111111111111111111');
    }
}
