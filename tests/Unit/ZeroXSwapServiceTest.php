<?php

namespace Tests\Unit;

use App\Services\EthereumTokenMetadataService;
use App\Services\ZeroXSwapService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ZeroXSwapServiceTest extends TestCase
{
    private const WALLET = '0x1111111111111111111111111111111111111111';

    private const TOKEN = '0x2222222222222222222222222222222222222222';

    public function test_firm_native_eth_quote_is_normalized_and_validated(): void
    {
        config()->set('services.zero_x.base_url', 'https://api.0x.test');
        config()->set('services.zero_x.api_key', 'zero-x-secret');

        Http::fake([
            'https://api.0x.test/swap/allowance-holder/quote*' => Http::response($this->response()),
        ]);

        $this->mock(EthereumTokenMetadataService::class, function ($mock): void {
            $mock->shouldReceive('resolve')
                ->once()
                ->with(self::TOKEN)
                ->andReturn([
                    'address' => self::TOKEN,
                    'symbol' => 'USDC',
                    'decimals' => 6,
                ]);
        });

        $quote = app(ZeroXSwapService::class)->quote(
            self::WALLET,
            self::TOKEN,
            '1000000000000000',
            100,
        );

        $this->assertSame(self::WALLET, $quote['transaction']['from']);
        $this->assertSame(self::TOKEN, $quote['transaction']['to']);
        $this->assertSame(['Uniswap_V3'], $quote['sources']);

        $this->assertSame('5000000', $quote['buy_amount']);
        $this->assertSame('4950000', $quote['minimum_buy_amount']);

        $this->assertSame([
            'address' => self::TOKEN,
            'amount' => '5000000',
            'amount_formatted' => '5',
            'minimum_amount' => '4950000',
            'minimum_amount_formatted' => '4.95',
            'symbol' => 'USDC',
            'decimals' => 6,
        ], $quote['output']);

        Http::assertSent(
            fn ($request): bool => $request->hasHeader('0x-api-key', 'zero-x-secret')
                && $request->hasHeader('0x-version', 'v2')
                && $request->url() === 'https://api.0x.test/swap/allowance-holder/quote?chainId=1&sellToken='
                    .ZeroXSwapService::NATIVE_ETH
                    .'&buyToken='.self::TOKEN
                    .'&sellAmount=1000000000000000'
                    .'&taker='.self::WALLET
                    .'&slippageBps=100'
        );
    }

    public function test_transaction_with_a_different_eth_value_is_rejected(): void
    {
        config()->set('services.zero_x.base_url', 'https://api.0x.test');
        config()->set('services.zero_x.api_key', 'zero-x-secret');
        $response = $this->response();
        $response['transaction']['value'] = '1';
        Http::fake(['*' => Http::response($response)]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsafe Ethereum transaction');

        app(ZeroXSwapService::class)->quote(self::WALLET, self::TOKEN, '1000000000000000', 100);
    }

    public function test_metadata_failure_does_not_block_a_valid_quote(): void
    {
        config()->set('services.zero_x.base_url', 'https://api.0x.test');
        config()->set('services.zero_x.api_key', 'zero-x-secret');

        Http::fake([
            'https://api.0x.test/swap/allowance-holder/quote*' => Http::response($this->response()),
        ]);

        $this->mock(EthereumTokenMetadataService::class, function ($mock): void {
            $mock->shouldReceive('resolve')
                ->once()
                ->with(self::TOKEN)
                ->andThrow(new RuntimeException('metadata unavailable'));
        });

        $quote = app(ZeroXSwapService::class)->quote(
            self::WALLET,
            self::TOKEN,
            '1000000000000000',
            100,
        );

        $this->assertSame('5000000', $quote['buy_amount']);
        $this->assertSame('4950000', $quote['minimum_buy_amount']);
        $this->assertNull($quote['output']);
        $this->assertSame(self::WALLET, $quote['transaction']['from']);
    }

    private function response(): array
    {
        return [
            'liquidityAvailable' => true,
            'sellToken' => ZeroXSwapService::NATIVE_ETH,
            'buyToken' => self::TOKEN,
            'sellAmount' => '1000000000000000',
            'buyAmount' => '5000000',
            'minBuyAmount' => '4950000',
            'totalNetworkFee' => '21000000000000',
            'zid' => 'quote-1',
            'route' => ['fills' => [['source' => 'Uniswap_V3']]],
            'transaction' => [
                'to' => self::TOKEN,
                'data' => '0x1234',
                'value' => '1000000000000000',
                'gas' => '21000',
                'gasPrice' => '1000000000',
            ],
        ];
    }
}
