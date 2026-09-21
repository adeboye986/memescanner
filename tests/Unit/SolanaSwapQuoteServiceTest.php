<?php

namespace Tests\Unit;

use App\Chain;
use App\Models\TokenScan;
use App\Services\SolanaSwapQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SolanaSwapQuoteServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_quote_is_normalized_and_uses_sol_input(): void
    {
        $this->createTokenMetadata();
        config()->set('services.jupiter.base_url', 'https://jupiter.test');
        Http::preventStrayRequests();
        Http::fake(['https://jupiter.test/quote*' => Http::response($this->validResponse())]);

        $quote = app(SolanaSwapQuoteService::class)->quote($this->outputMint(), '10000000', 100);

        $this->assertSame(SolanaSwapQuoteService::SOL_MINT, $quote['input']['mint']);
        $this->assertSame('2500000', $quote['output']['amount']);
        $this->assertSame('2.5', $quote['output']['amount_formatted']);
        $this->assertSame('2.475', $quote['minimum_received_formatted']);
        $this->assertSame('USDC', $quote['output']['symbol']);
        $this->assertSame(6, $quote['output']['decimals']);
        $this->assertSame('2475000', $quote['minimum_received']);
        $this->assertSame('GoonFi V2', $quote['route'][0]['label']);
        $this->assertNull($quote['route'][0]['fee_amount']);
        $this->assertNull($quote['route'][0]['fee_mint']);
        Http::assertSent(fn ($request): bool => $request['inputMint'] === SolanaSwapQuoteService::SOL_MINT
            && $request['outputMint'] === $this->outputMint()
            && $request['amount'] === '10000000'
            && $request['slippageBps'] === 100);
    }

    public function test_valid_optional_fee_fields_are_normalized(): void
    {
        $this->createTokenMetadata();
        config()->set('services.jupiter.base_url', 'https://jupiter.test');
        Http::fake(['https://jupiter.test/quote*' => Http::response($this->validResponse([
            'feeAmount' => '10',
            'feeMint' => SolanaSwapQuoteService::SOL_MINT,
        ]))]);

        $quote = app(SolanaSwapQuoteService::class)->quote($this->outputMint(), '10000000', 100);

        $this->assertSame('10', $quote['route'][0]['fee_amount']);
        $this->assertSame(SolanaSwapQuoteService::SOL_MINT, $quote['route'][0]['fee_mint']);
    }

    public function test_valid_quote_survives_unavailable_metadata_and_preserves_stored_symbol(): void
    {
        $this->createTokenMetadata(['symbol' => 'USDT', 'raw_data' => []]);
        config()->set('services.jupiter.base_url', 'https://jupiter.test');
        config()->set('services.solana.rpc_url', 'https://solana.test');
        Http::preventStrayRequests();
        Http::fake([
            'https://jupiter.test/quote*' => Http::response($this->validResponse()),
            'https://solana.test' => Http::failedConnection('timeout'),
        ]);

        $quote = app(SolanaSwapQuoteService::class)->quote($this->outputMint(), '10000000', 100);

        $this->assertSame('2500000', $quote['output']['amount']);
        $this->assertSame('USDT', $quote['output']['symbol']);
        $this->assertNull($quote['output']['decimals']);
        $this->assertNull($quote['output']['amount_formatted']);
        $this->assertSame('2475000', $quote['minimum_received']);
        $this->assertNull($quote['minimum_received_formatted']);
    }

    public function test_valid_quote_survives_malformed_rpc_metadata(): void
    {
        config()->set('services.jupiter.base_url', 'https://jupiter.test');
        config()->set('services.solana.rpc_url', 'https://solana.test');
        Http::preventStrayRequests();
        Http::fake([
            'https://jupiter.test/quote*' => Http::response($this->validResponse()),
            'https://solana.test' => Http::response([
                'jsonrpc' => '2.0',
                'result' => ['value' => ['decimals' => '6']],
                'id' => 1,
            ]),
        ]);

        $quote = app(SolanaSwapQuoteService::class)->quote($this->outputMint(), '10000000', 100);

        $this->assertNull($quote['output']['symbol']);
        $this->assertNull($quote['output']['decimals']);
        $this->assertNull($quote['output']['amount_formatted']);
        $this->assertNull($quote['minimum_received_formatted']);
    }

    public function test_malformed_optional_fee_amount_is_rejected(): void
    {
        config()->set('services.jupiter.base_url', 'https://jupiter.test');
        Http::fake(['https://jupiter.test/quote*' => Http::response($this->validResponse([
            'feeAmount' => '1.5',
        ]))]);

        $this->expectException(RuntimeException::class);

        app(SolanaSwapQuoteService::class)->quote($this->outputMint(), '10000000', 100);
    }

    public function test_empty_optional_fee_mint_is_rejected(): void
    {
        config()->set('services.jupiter.base_url', 'https://jupiter.test');
        Http::fake(['https://jupiter.test/quote*' => Http::response($this->validResponse([
            'feeMint' => '',
        ]))]);

        $this->expectException(RuntimeException::class);

        app(SolanaSwapQuoteService::class)->quote($this->outputMint(), '10000000', 100);
    }

    public function test_malformed_optional_fee_mint_is_rejected(): void
    {
        config()->set('services.jupiter.base_url', 'https://jupiter.test');
        Http::fake(['https://jupiter.test/quote*' => Http::response($this->validResponse([
            'feeMint' => 'not-a-solana-mint',
        ]))]);

        $this->expectException(RuntimeException::class);

        app(SolanaSwapQuoteService::class)->quote($this->outputMint(), '10000000', 100);
    }

    public function test_route_percentage_total_must_remain_exactly_one_hundred(): void
    {
        config()->set('services.jupiter.base_url', 'https://jupiter.test');
        $response = $this->validResponse();
        $response['routePlan'][0]['percent'] = 99;
        Http::fake(['https://jupiter.test/quote*' => Http::response($response)]);

        $this->expectException(RuntimeException::class);

        app(SolanaSwapQuoteService::class)->quote($this->outputMint(), '10000000', 100);
    }

    public function test_malformed_response_is_rejected(): void
    {
        config()->set('services.jupiter.base_url', 'https://jupiter.test');
        Http::fake(['https://jupiter.test/quote*' => Http::response(['outAmount' => '2500000'])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('provider returned invalid data');

        app(SolanaSwapQuoteService::class)->quote($this->outputMint(), '10000000', 100);
    }

    public function test_invalid_output_amount_is_rejected(): void
    {
        config()->set('services.jupiter.base_url', 'https://jupiter.test');
        Http::fake(['https://jupiter.test/quote*' => Http::response([...$this->validResponse(), 'outAmount' => '0'])]);

        $this->expectException(RuntimeException::class);

        app(SolanaSwapQuoteService::class)->quote($this->outputMint(), '10000000', 100);
    }

    public function test_provider_error_is_handled_safely(): void
    {
        config()->set('services.jupiter.base_url', 'https://jupiter.test');
        Http::fake(['https://jupiter.test/quote*' => Http::response(['private' => 'details'], 429)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A swap quote is temporarily unavailable.');

        app(SolanaSwapQuoteService::class)->quote($this->outputMint(), '10000000', 100);
    }

    public function test_connection_failure_is_normalized_to_runtime_exception(): void
    {
        config()->set('services.jupiter.base_url', 'https://jupiter.test');
        Http::fake(['https://jupiter.test/quote*' => Http::failedConnection('timeout')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A swap quote is temporarily unavailable.');

        app(SolanaSwapQuoteService::class)->quote($this->outputMint(), '10000000', 100);
    }

    /** @return array<string, mixed> */
    private function validResponse(array $swapOverrides = []): array
    {
        return [
            'inputMint' => SolanaSwapQuoteService::SOL_MINT,
            'inAmount' => '10000000',
            'outputMint' => $this->outputMint(),
            'outAmount' => '2500000',
            'otherAmountThreshold' => '2475000',
            'swapMode' => 'ExactIn',
            'slippageBps' => 100,
            'priceImpactPct' => '0.001',
            'routePlan' => [[
                'swapInfo' => array_merge([
                    'ammKey' => 'GoonFi1111111111111111111111111111111111111',
                    'label' => 'GoonFi V2',
                    'inputMint' => SolanaSwapQuoteService::SOL_MINT,
                    'outputMint' => $this->outputMint(),
                    'inAmount' => '10000000',
                    'outAmount' => '2500000',
                    'updateContextSlot' => '445090017',
                ], $swapOverrides),
                'percent' => 100,
                'bps' => null,
            ]],
        ];
    }

    private function outputMint(): string
    {
        return 'Es9vMFrzaCERmJfrF4H2FYD1vNQyVJZZzZzZzZzZzZz';
    }

    /** @param array<string, mixed> $overrides */
    private function createTokenMetadata(array $overrides = []): TokenScan
    {
        return TokenScan::query()->create([
            'chain' => Chain::Solana,
            'address' => $this->outputMint(),
            'symbol' => 'USDC',
            'raw_data' => ['decimals' => 6],
            'first_seen_at' => now(),
            'last_scanned_at' => now(),
            ...$overrides,
        ]);
    }
}
