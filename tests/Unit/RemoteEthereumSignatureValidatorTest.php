<?php

namespace Tests\Unit;

use App\Services\RemoteEthereumSignatureValidator;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class RemoteEthereumSignatureValidatorTest extends TestCase
{
    public function test_signature_is_verified_by_the_configured_https_validator(): void
    {
        config()->set('services.solana_transaction_validator.url', 'https://validator.test');
        config()->set('services.solana_transaction_validator.api_key', 'secret');
        $address = '0x1111111111111111111111111111111111111111';
        $signature = '0x'.str_repeat('ab', 65);
        Http::fake(['https://validator.test/v1/verify-ethereum-signature' => Http::response([
            'valid' => true,
            'recovered_address' => $address,
        ])]);

        $this->assertSame($address, app(RemoteEthereumSignatureValidator::class)->verify('exact message', $signature, $address));
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer secret')
            && $request['message'] === 'exact message'
            && $request['signature'] === $signature
            && $request['expected_wallet'] === $address);
    }

    public function test_non_https_validator_is_rejected_without_a_request(): void
    {
        config()->set('services.solana_transaction_validator.url', 'http://validator.test');
        config()->set('services.solana_transaction_validator.api_key', 'secret');
        Http::preventStrayRequests();
        $this->expectException(RuntimeException::class);

        app(RemoteEthereumSignatureValidator::class)->verify('message', 'signature', 'wallet');
    }
}
