<?php

namespace Tests\Unit;

use App\Services\SolanaTransactionValidator;
use RuntimeException;
use Tests\TestCase;

class SolanaTransactionValidatorTest extends TestCase
{
    private const WALLET = 'GmaDrppBC7P5ARKV8g3djiwP89vz1jLK23V2GBjuAEGB';

    private const PREPARED = 'AQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAACAAQABAupKbGPinFIKvvVQexMuxfmVR3auvr57kkIe6mkURtIsAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAACAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAEBAQABAQA=';

    private const SIGNED = 'ASpoF6a29X20lFSDkej1fD7+sAx/HLdh+iw6yUuBn9Zr0pty9sridizklww/j4/sQ4uheyXvo8kCDus6BuM6cwCAAQABAupKbGPinFIKvvVQexMuxfmVR3auvr57kkIe6mkURtIsAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAACAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAEBAQABAQA=';

    public function test_laravel_wrapper_inspects_and_compares_canonical_v0_transactions(): void
    {
        $validator = app(SolanaTransactionValidator::class);

        $prepared = $validator->inspectPrepared(self::PREPARED, self::WALLET);
        $signed = $validator->compareSigned(self::PREPARED, self::SIGNED, self::WALLET);

        $this->assertTrue($prepared['valid']);
        $this->assertSame(0, $prepared['transaction_version']);
        $this->assertSame(self::WALLET, $prepared['fee_payer']);
        $this->assertFalse($prepared['expected_wallet_signature_present']);
        $this->assertTrue($signed['valid']);
        $this->assertSame($prepared['message_hash'], $signed['message_hash']);
        $this->assertTrue($signed['expected_wallet_signature_present']);
    }

    public function test_cli_failures_are_returned_as_safe_normalized_results(): void
    {
        $result = app(SolanaTransactionValidator::class)->inspectPrepared('not-base64', 'not-a-wallet');

        $this->assertSame([
            'valid' => false,
            'error_code' => 'invalid_base64',
        ], $result);
    }

    public function test_oversized_transaction_is_rejected_before_process_execution(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The Solana transaction is invalid.');

        app(SolanaTransactionValidator::class)->inspectPrepared(str_repeat('A', 4097), 'not-a-wallet');
    }

    public function test_missing_node_runtime_is_normalized_without_exposing_process_details(): void
    {
        config()->set('services.solana.transaction_validator_node', '/definitely/missing/node');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Solana transaction validation is unavailable.');

        app(SolanaTransactionValidator::class)->inspectPrepared('not-base64', 'not-a-wallet');
    }
}
