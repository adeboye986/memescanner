<?php

namespace Tests\Unit;

use App\Services\EthereumService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EthereumServiceTest extends TestCase
{
    public function test_balance_is_read_as_an_exact_unsigned_integer(): void
    {
        config()->set('services.ethereum.rpc_url', 'https://rpc.test');
        Http::fake(['https://rpc.test' => Http::response([
            'jsonrpc' => '2.0', 'id' => 1, 'result' => '0xde0b6b3a7640000',
        ])]);

        $this->assertSame('1000000000000000000', app(EthereumService::class)->getBalanceWei('0x1111111111111111111111111111111111111111'));
        Http::assertSent(fn ($request): bool => $request['method'] === 'eth_getBalance'
            && $request['params'] === ['0x1111111111111111111111111111111111111111', 'latest']);
    }

    public function test_read_only_contract_call_uses_eth_call_with_exact_payload(): void
    {
        config()->set('services.ethereum.rpc_url', 'https://rpc.test');

        Http::fake([
            'https://rpc.test' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => '0x0000000000000000000000000000000000000000000000000000000000000006',
            ]),
        ]);

        $result = app(EthereumService::class)->call(
            '0xA0b86991c6218b36c1d19d4a2e9eb0ce3606eb48',
            '0x313ce567',
        );

        $this->assertSame(
            '0x0000000000000000000000000000000000000000000000000000000000000006',
            $result,
        );

        Http::assertSent(fn ($request): bool => $request['method'] === 'eth_call'
            && $request['params'] === [
                [
                    'to' => '0xa0b86991c6218b36c1d19d4a2e9eb0ce3606eb48',
                    'data' => '0x313ce567',
                ],
                'latest',
            ]
        );
    }

    public function test_contract_call_rejects_invalid_address_without_rpc(): void
    {
        config()->set('services.ethereum.rpc_url', 'https://rpc.test');

        Http::preventStrayRequests();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Ethereum contract address is invalid.');

        app(EthereumService::class)->call(
            'not-an-address',
            '0x313ce567',
        );
    }

    public function test_contract_call_rejects_malformed_rpc_response(): void
    {
        config()->set('services.ethereum.rpc_url', 'https://rpc.test');

        Http::fake([
            'https://rpc.test' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => 'not-hex',
            ]),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Ethereum RPC returned an invalid contract call response.');

        app(EthereumService::class)->call(
            '0xA0b86991c6218b36c1d19d4a2e9eb0ce3606eb48',
            '0x313ce567',
        );
    }
}
