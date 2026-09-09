<?php

namespace Tests\Unit;

use App\Services\RemoteSolanaTransactionValidator;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class RemoteSolanaTransactionValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.solana_transaction_validator.url', 'https://validator.example.com');
        config()->set('services.solana_transaction_validator.api_key', 'test-secret');
        config()->set('services.solana_transaction_validator.timeout_seconds', 5);
    }

    public function test_it_inspects_prepared_transaction(): void
    {
        Http::fake([
            'https://validator.example.com/v1/inspect-prepared' => Http::response(
                $this->validResponse(false),
                200
            ),
        ]);

        $service = app(RemoteSolanaTransactionValidator::class);

        $result = $service->inspectPrepared('prepared-base64', 'Wallet111');

        $this->assertTrue($result['valid']);
        $this->assertFalse($result['expected_wallet_signature_present']);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://validator.example.com/v1/inspect-prepared'
                && $request->hasHeader('Authorization', 'Bearer test-secret')
                && $request['transaction'] === 'prepared-base64'
                && $request['expected_wallet'] === 'Wallet111';
        });
    }

    public function test_it_compares_signed_transaction(): void
    {
        Http::fake([
            'https://validator.example.com/v1/compare-signed' => Http::response(
                $this->validResponse(true),
                200
            ),
        ]);

        $service = app(RemoteSolanaTransactionValidator::class);

        $result = $service->compareSigned(
            'prepared-base64',
            'signed-base64',
            'Wallet111'
        );

        $this->assertTrue($result['valid']);
        $this->assertTrue($result['expected_wallet_signature_present']);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://validator.example.com/v1/compare-signed'
                && $request->hasHeader('Authorization', 'Bearer test-secret')
                && $request['prepared_transaction'] === 'prepared-base64'
                && $request['signed_transaction'] === 'signed-base64'
                && $request['expected_wallet'] === 'Wallet111';
        });
    }

    public function test_it_rejects_unconfigured_service(): void
    {
        config()->set('services.solana_transaction_validator.url', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Solana transaction validator is not configured.'
        );

        app(RemoteSolanaTransactionValidator::class)
            ->inspectPrepared('prepared', 'Wallet111');
    }

    public function test_it_rejects_non_https_url(): void
    {
        config()->set(
            'services.solana_transaction_validator.url',
            'http://validator.example.com'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Solana transaction validator requires HTTPS.'
        );

        app(RemoteSolanaTransactionValidator::class)
            ->inspectPrepared('prepared', 'Wallet111');
    }

    public function test_it_normalizes_provider_failure(): void
    {
        Http::fake([
            '*' => Http::response([
                'error' => 'sensitive-provider-detail',
            ], 500),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Solana transaction validation failed.'
        );

        app(RemoteSolanaTransactionValidator::class)
            ->inspectPrepared('prepared', 'Wallet111');
    }

    public function test_it_rejects_invalid_response_schema(): void
    {
        Http::fake([
            '*' => Http::response([
                'valid' => true,
                'transaction_version' => 0,
            ], 200),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Solana transaction validator returned an invalid response.'
        );

        app(RemoteSolanaTransactionValidator::class)
            ->inspectPrepared('prepared', 'Wallet111');
    }

    private function validResponse(bool $signed): array
    {
        return [
            'valid' => true,
            'transaction_version' => 0,
            'message_hash' => str_repeat('a', 64),
            'fee_payer' => 'Wallet111',
            'required_signer_addresses' => ['Wallet111'],
            'expected_wallet_is_required_signer' => true,
            'expected_wallet_is_fee_payer' => true,
            'signature_count' => 1,
            'expected_wallet_signature_present' => $signed,
            'recent_blockhash' => 'Blockhash111',
            'address_lookup_table_references' => [],
        ];
    }
}
