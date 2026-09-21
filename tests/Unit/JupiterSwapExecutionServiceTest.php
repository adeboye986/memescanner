<?php

namespace Tests\Unit;

use App\Models\SolanaSwapAttempt;
use App\Services\JupiterSwapExecutionService;
use App\Services\RemoteSolanaTransactionValidator;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class JupiterSwapExecutionServiceTest extends TestCase
{
    public function test_valid_signed_transaction_is_submitted_with_its_original_request_id(): void
    {
        config(['services.jupiter.swap_v2_base_url' => 'https://api.jup.ag/swap/v2']);
        Http::preventStrayRequests();
        Http::fake(['https://api.jup.ag/swap/v2/execute' => Http::response([
            'status' => 'Success',
            'signature' => 'chain-signature',
        ])]);

        $validator = Mockery::mock(RemoteSolanaTransactionValidator::class);

        $result = (new JupiterSwapExecutionService($validator))->execute(
            $this->attempt(),
            base64_encode('signed'),
        );

        $this->assertSame('submitted', $result['status']);
        $this->assertSame('chain-signature', $result['signature']);
        Http::assertSent(fn ($request): bool => $request['requestId'] === 'request-123'
            && $request['signedTransaction'] === base64_encode('signed'));
    }

    public function test_success_response_with_different_signature_is_rejected(): void
    {
        config(['services.jupiter.swap_v2_base_url' => 'https://api.jup.ag/swap/v2']);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.jup.ag/swap/v2/execute' => Http::response([
                'status' => 'Success',
                'signature' => 'different-signature',
            ]),
        ]);

        $validator = Mockery::mock(RemoteSolanaTransactionValidator::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Jupiter returned a different transaction signature. Check transaction history before retrying.'
        );

        (new JupiterSwapExecutionService($validator))->execute(
            $this->attempt(),
            base64_encode('signed'),
        );
    }

    public function test_signed_transaction_validation_returns_its_verified_transaction_signature(): void
    {
        Http::preventStrayRequests();

        $validator = Mockery::mock(RemoteSolanaTransactionValidator::class);
        $validator->shouldReceive('compareSigned')
            ->once()
            ->with(base64_encode('prepared'), base64_encode('signed'), 'wallet-address')
            ->andReturn($this->validation(true));

        $result = (new JupiterSwapExecutionService($validator))->validateSigned(
            $this->attempt(),
            base64_encode('signed'),
            'wallet-address',
        );

        $this->assertSame(
            str_repeat('1', 88),
            $result['transaction_signature']
        );

        Http::assertNothingSent();
    }

    public function test_changed_message_is_never_submitted(): void
    {
        Http::preventStrayRequests();
        $validator = Mockery::mock(RemoteSolanaTransactionValidator::class);
        $validator->shouldReceive('compareSigned')->once()->andReturn([
            ...$this->validation(true),
            'message_hash' => str_repeat('b', 64),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('failed validation');

        try {
            (new JupiterSwapExecutionService($validator))->validateSigned(
                $this->attempt(),
                base64_encode('signed'),
                'wallet-address',
            );
        } finally {
            Http::assertNothingSent();
        }
    }

    private function attempt(): SolanaSwapAttempt
    {
        return new SolanaSwapAttempt([
            'request_id' => 'request-123',
            'message_hash' => str_repeat('a', 64),
            'prepared_transaction' => base64_encode('prepared'),
            'transaction_signature' => 'chain-signature',
        ]);
    }

    /** @return array<string, mixed> */
    private function validation(bool $signed): array
    {
        return [
            'valid' => true,
            'transaction_version' => 0,
            'message_hash' => str_repeat('a', 64),
            'fee_payer' => 'wallet-address',
            'required_signer_addresses' => ['wallet-address'],
            'expected_wallet_is_required_signer' => true,
            'expected_wallet_is_fee_payer' => true,
            'signature_count' => 1,
            'expected_wallet_signature_present' => $signed,
            'transaction_signature' => $signed
                ? str_repeat('1', 88)
                : null,
            'recent_blockhash' => 'blockhash',
            'address_lookup_table_references' => [],
        ];
    }
}
