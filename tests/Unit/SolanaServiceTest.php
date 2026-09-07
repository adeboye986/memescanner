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
}
