<?php

namespace Tests\Unit;

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
        config()->set('services.jupiter.base_url', 'https://jupiter.test');
        Http::preventStrayRequests();
        Http::fake(['https://jupiter.test/quote*' => Http::response($this->validResponse())]);

        $quote = app(SolanaSwapQuoteService::class)->quote($this->outputMint(), '10000000', 100);

        $this->assertSame(SolanaSwapQuoteService::SOL_MINT, $quote['input']['mint']);
        $this->assertSame('2500000', $quote['output']['amount']);
        $this->assertSame('2475000', $quote['minimum_received']);
        $this->assertSame('Raydium', $quote['route'][0]['label']);
        Http::assertSent(fn ($request): bool => $request['inputMint'] === SolanaSwapQuoteService::SOL_MINT
            && $request['outputMint'] === $this->outputMint()
            && $request['amount'] === '10000000'
            && $request['slippageBps'] === 100);
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
    private function validResponse(): array
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
                'swapInfo' => ['label' => 'Raydium', 'feeAmount' => '10', 'feeMint' => SolanaSwapQuoteService::SOL_MINT],
                'percent' => 100,
            ]],
        ];
    }

    private function outputMint(): string
    {
        return 'Es9vMFrzaCERmJfrF4H2FYD1vNQyVJZZzZzZzZzZzZz';
    }
}
