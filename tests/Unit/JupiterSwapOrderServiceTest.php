<?php

namespace Tests\Unit;

use App\Services\JupiterSwapOrderService;
use App\Services\RemoteSolanaTransactionValidator;
use App\Services\SolanaSwapQuoteService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class JupiterSwapOrderServiceTest extends TestCase
{
    private const WALLET = '6Z7KvfSvatJqaj5kKB53TzZDxMJB8w3e2k2qz6qfYCvP';

    private const OUTPUT_MINT = 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v';

    public function test_valid_jupiter_order_is_validated_before_being_returned(): void
    {
        config()->set(
            'services.jupiter.swap_v2_base_url',
            'https://jupiter.test'
        );
        config()->set('services.jupiter.api_key', 'test-api-key');

        Http::preventStrayRequests();

        $transaction = base64_encode('safe-test-transaction');

        Http::fake([
            'https://jupiter.test/order*' => Http::response([
                'transaction' => $transaction,
                'requestId' => 'request-123',
            ]),
        ]);

        $this->mock(
            RemoteSolanaTransactionValidator::class,
            function ($mock) use ($transaction): void {
                $mock->shouldReceive('inspectPrepared')
                    ->once()
                    ->with($transaction, self::WALLET)
                    ->andReturn($this->validValidation());
            }
        );

        $result = app(JupiterSwapOrderService::class)->prepare(
            self::WALLET,
            self::OUTPUT_MINT,
            '1000000',
            100
        );

        $this->assertSame($transaction, $result['transaction']);
        $this->assertSame('request-123', $result['request_id']);

        Http::assertSent(function ($request): bool {
            return $request->url()
                === 'https://jupiter.test/order?inputMint='
                .SolanaSwapQuoteService::SOL_MINT
                .'&outputMint='.self::OUTPUT_MINT
                .'&amount=1000000'
                .'&taker='.self::WALLET
                .'&slippageBps=100'
                && $request->hasHeader('x-api-key', 'test-api-key');
        });
    }

    public function test_order_is_rejected_when_wallet_is_not_required_signer(): void
    {
        $this->fakeValidJupiterOrder();

        $validation = $this->validValidation();
        $validation['expected_wallet_is_required_signer'] = false;

        $this->mock(
            RemoteSolanaTransactionValidator::class,
            fn ($mock) => $mock->shouldReceive('inspectPrepared')
                ->once()
                ->andReturn($validation)
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'prepared swap transaction failed validation'
        );

        app(JupiterSwapOrderService::class)->prepare(
            self::WALLET,
            self::OUTPUT_MINT,
            '1000000',
            100
        );
    }

    public function test_order_is_rejected_when_wallet_is_not_fee_payer(): void
    {
        $this->fakeValidJupiterOrder();

        $validation = $this->validValidation();
        $validation['expected_wallet_is_fee_payer'] = false;

        $this->mock(
            RemoteSolanaTransactionValidator::class,
            fn ($mock) => $mock->shouldReceive('inspectPrepared')
                ->once()
                ->andReturn($validation)
        );

        $this->expectException(RuntimeException::class);

        app(JupiterSwapOrderService::class)->prepare(
            self::WALLET,
            self::OUTPUT_MINT,
            '1000000',
            100
        );
    }

    public function test_pre_signed_customer_transaction_is_rejected(): void
    {
        $this->fakeValidJupiterOrder();

        $validation = $this->validValidation();
        $validation['expected_wallet_signature_present'] = true;

        $this->mock(
            RemoteSolanaTransactionValidator::class,
            fn ($mock) => $mock->shouldReceive('inspectPrepared')
                ->once()
                ->andReturn($validation)
        );

        $this->expectException(RuntimeException::class);

        app(JupiterSwapOrderService::class)->prepare(
            self::WALLET,
            self::OUTPUT_MINT,
            '1000000',
            100
        );
    }

    public function test_malformed_provider_transaction_never_reaches_validator(): void
    {
        config()->set(
            'services.jupiter.swap_v2_base_url',
            'https://jupiter.test'
        );

        Http::fake([
            'https://jupiter.test/order*' => Http::response([
                'transaction' => 'not valid base64!',
                'requestId' => 'request-123',
            ]),
        ]);

        $this->mock(
            RemoteSolanaTransactionValidator::class,
            fn ($mock) => $mock->shouldNotReceive('inspectPrepared')
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'provider returned invalid data'
        );

        app(JupiterSwapOrderService::class)->prepare(
            self::WALLET,
            self::OUTPUT_MINT,
            '1000000',
            100
        );
    }

    public function test_provider_failure_is_normalized(): void
    {
        config()->set(
            'services.jupiter.swap_v2_base_url',
            'https://jupiter.test'
        );

        Http::fake([
            'https://jupiter.test/order*' => Http::response([
                'secret_provider_detail' => 'do not expose',
            ], 500),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'A swap order is temporarily unavailable.'
        );

        app(JupiterSwapOrderService::class)->prepare(
            self::WALLET,
            self::OUTPUT_MINT,
            '1000000',
            100
        );
    }

    public function test_non_https_jupiter_endpoint_is_rejected(): void
    {
        config()->set(
            'services.jupiter.swap_v2_base_url',
            'http://jupiter.test'
        );

        Http::preventStrayRequests();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Jupiter Swap V2 requires HTTPS.'
        );

        app(JupiterSwapOrderService::class)->prepare(
            self::WALLET,
            self::OUTPUT_MINT,
            '1000000',
            100
        );
    }

    private function fakeValidJupiterOrder(): void
    {
        config()->set(
            'services.jupiter.swap_v2_base_url',
            'https://jupiter.test'
        );

        Http::fake([
            'https://jupiter.test/order*' => Http::response([
                'transaction' => base64_encode('safe-test-transaction'),
                'requestId' => 'request-123',
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    private function validValidation(): array
    {
        return [
            'valid' => true,
            'transaction_version' => 0,
            'message_hash' => str_repeat('a', 64),
            'fee_payer' => self::WALLET,
            'required_signer_addresses' => [self::WALLET],
            'expected_wallet_is_required_signer' => true,
            'expected_wallet_is_fee_payer' => true,
            'signature_count' => 1,
            'expected_wallet_signature_present' => false,
            'recent_blockhash' => '11111111111111111111111111111111',
            'address_lookup_table_references' => [],
        ];
    }
}
