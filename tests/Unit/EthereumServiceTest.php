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
}
