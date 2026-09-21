<?php

namespace Tests\Unit;

use App\Services\EthereumService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
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

        $this->expectException(RuntimeException::class);
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

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ethereum RPC returned an invalid contract call response.');

        app(EthereumService::class)->call(
            '0xA0b86991c6218b36c1d19d4a2e9eb0ce3606eb48',
            '0x313ce567',
        );
    }

    public function test_transaction_is_read_and_normalized_by_hash(): void
    {
        config(['services.ethereum.rpc_url' => 'https://ethereum.test']);

        Http::fake([
            'https://ethereum.test' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => [
                    'hash' => '0x'.str_repeat('A', 64),
                    'from' => '0x'.str_repeat('1', 40),
                    'to' => '0x'.str_repeat('2', 40),
                    'value' => '0x186a0',
                    'input' => '0xabcdef',
                ],
            ]),
        ]);

        $result = app(EthereumService::class)
            ->getTransactionByHash('0x'.str_repeat('A', 64));

        $this->assertSame('0x'.str_repeat('a', 64), $result['hash']);
        $this->assertSame('0x'.str_repeat('1', 40), $result['from']);
        $this->assertSame('0x'.str_repeat('2', 40), $result['to']);
        $this->assertSame('100000', $result['value']);
        $this->assertSame('0xabcdef', $result['input']);

        Http::assertSent(fn ($request) => $request->url() === 'https://ethereum.test'
            && $request['method'] === 'eth_getTransactionByHash'
            && $request['params'] === ['0x'.str_repeat('a', 64)]
        );
    }

    public function test_transaction_lookup_rejects_invalid_hash_without_rpc(): void
    {
        config(['services.ethereum.rpc_url' => 'https://ethereum.test']);

        Http::fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ethereum transaction hash is invalid.');

        app(EthereumService::class)->getTransactionByHash('not-a-hash');

        Http::assertNothingSent();
    }

    public function test_transaction_lookup_rejects_missing_rpc_transaction(): void
    {
        config(['services.ethereum.rpc_url' => 'https://ethereum.test']);

        Http::fake([
            'https://ethereum.test' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => null,
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Ethereum transaction could not be verified.');

        app(EthereumService::class)
            ->getTransactionByHash('0x'.str_repeat('a', 64));
    }

    public function test_transaction_receipt_returns_null_when_transaction_is_not_mined_yet(): void
    {
        config(['services.ethereum.rpc_url' => 'https://ethereum.test']);

        Http::fake([
            'https://ethereum.test' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => null,
            ]),
        ]);

        $result = app(EthereumService::class)
            ->getTransactionReceipt('0x'.str_repeat('a', 64));

        $this->assertNull($result);

        Http::assertSent(fn ($request) => $request->url() === 'https://ethereum.test'
            && $request['method'] === 'eth_getTransactionReceipt'
            && $request['params'] === ['0x'.str_repeat('a', 64)]
        );
    }

    public function test_successful_transaction_receipt_is_normalized(): void
    {
        config(['services.ethereum.rpc_url' => 'https://ethereum.test']);

        Http::fake([
            'https://ethereum.test' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => [
                    'transactionHash' => '0x'.str_repeat('A', 64),
                    'status' => '0x1',
                    'blockNumber' => '0x10',
                    'gasUsed' => '0x5208',
                    'effectiveGasPrice' => '0x3b9aca00',
                ],
            ]),
        ]);

        $result = app(EthereumService::class)
            ->getTransactionReceipt('0x'.str_repeat('A', 64));

        $this->assertSame(
            '0x'.str_repeat('a', 64),
            $result['transaction_hash'],
        );
        $this->assertTrue($result['succeeded']);
        $this->assertSame('16', $result['block_number']);
        $this->assertSame('21000', $result['gas_used']);
        $this->assertSame('1000000000', $result['effective_gas_price_wei']);
        $this->assertSame('21000000000000', $result['actual_network_fee_wei']);
    }

    public function test_failed_transaction_receipt_is_normalized(): void
    {
        config(['services.ethereum.rpc_url' => 'https://ethereum.test']);

        Http::fake([
            'https://ethereum.test' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => [
                    'transactionHash' => '0x'.str_repeat('b', 64),
                    'status' => '0x0',
                    'blockNumber' => '0x2a',
                    'gasUsed' => '0x5208',
                    'effectiveGasPrice' => '0x3b9aca00',
                ],
            ]),
        ]);

        $result = app(EthereumService::class)
            ->getTransactionReceipt('0x'.str_repeat('b', 64));

        $this->assertSame(
            '0x'.str_repeat('b', 64),
            $result['transaction_hash'],
        );
        $this->assertFalse($result['succeeded']);
        $this->assertSame('42', $result['block_number']);
        $this->assertSame('21000', $result['gas_used']);
        $this->assertSame('1000000000', $result['effective_gas_price_wei']);
        $this->assertSame('21000000000000', $result['actual_network_fee_wei']);
    }
}
