<?php

namespace Tests\Feature;

use App\Exceptions\EthereumAccountingException;
use App\Services\EthereumAccountingRpc;
use App\Services\EthereumTransferExtractor;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EthereumAccountingRpcTest extends TestCase
{
    private const HASH = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const BLOCK = '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function test_receipt_normalizes_identity_exact_fee_and_identical_duplicate_logs(): void
    {
        $receipt = $this->receipt();
        $receipt['logs'][] = $receipt['logs'][0];
        $this->fake($receipt);
        $parsed = app(EthereumAccountingRpc::class)->receipt(self::HASH);
        $this->assertSame('100', $parsed['block_number']);
        $this->assertSame('21000', $parsed['network_fee_wei']);
        $this->assertCount(1, $parsed['logs']);
    }

    #[DataProvider('malformedReceipts')]
    public function test_receipt_rejects_malformed_or_contradictory_evidence(string $case): void
    {
        $receipt = $this->receipt();
        switch ($case) {
            case 'hash': $receipt['transactionHash'] = '0x12';
                break;
            case 'different hash': $receipt['transactionHash'] = self::BLOCK;
                break;
            case 'block hash': $receipt['blockHash'] = '0x12';
                break;
            case 'index': $receipt['transactionIndex'] = '0x00';
                break;
            case 'log transaction': $receipt['logs'][0]['transactionHash'] = self::BLOCK;
                break;
            case 'log block': $receipt['logs'][0]['blockHash'] = self::HASH;
                break;
            case 'log index': $receipt['logs'][0]['transactionIndex'] = '0x1';
                break;
            case 'log height': $receipt['logs'][0]['blockNumber'] = '0x65';
                break;
            case 'removed': $receipt['logs'][0]['removed'] = true;
                break;
            case 'null removed': $receipt['logs'][0]['removed'] = null;
                break;
            case 'topics': $receipt['logs'][0]['topics'] = ['0x01'];
                break;
            case 'data': $receipt['logs'][0]['data'] = '0x1';
                break;
            case 'oversized data': $receipt['logs'][0]['data'] = '0x'.str_repeat('00', 32769);
                break;
            case 'duplicate': $receipt['logs'][] = [...$receipt['logs'][0], 'data' => '0x'.str_repeat('1', 64)];
                break;
            case 'missing logs': unset($receipt['logs']);
                break;
            case 'log count': $receipt['logs'] = array_fill(0, EthereumAccountingRpc::MAX_LOGS + 1, $receipt['logs'][0]);
                break;
            case 'quantity length': $receipt['gasUsed'] = '0x'.str_repeat('f', 65);
                break;
        }
        $this->fake($receipt);
        $this->expectException(EthereumAccountingException::class);
        app(EthereumAccountingRpc::class)->receipt(self::HASH);
    }

    public static function malformedReceipts(): array
    {
        return array_map(fn ($case) => [$case], ['hash', 'different hash', 'block hash', 'index', 'log transaction', 'log block', 'log index', 'log height', 'removed', 'null removed', 'topics', 'data', 'oversized data', 'duplicate', 'missing logs', 'log count', 'quantity length']);
    }

    #[DataProvider('envelopes')]
    public function test_envelope_and_response_bounds_fail_closed(mixed $body): void
    {
        config(['services.ethereum.rpc_url' => 'https://ethereum.test']);
        Http::preventStrayRequests();
        Http::fake(['ethereum.test' => Http::response($body)]);
        $this->expectException(EthereumAccountingException::class);
        app(EthereumAccountingRpc::class)->receipt(self::HASH);
    }

    public static function envelopes(): array
    {
        return [[['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32000], 'result' => ['status' => '0x1']]], [['jsonrpc' => '1.0', 'id' => 1, 'result' => null]],
            [['jsonrpc' => '2.0', 'id' => '1', 'result' => null]], [['jsonrpc' => '2.0', 'id' => 1]],
            [['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -1]]], [str_repeat('x', EthereumAccountingRpc::MAX_BYTES + 1)]];
    }

    public function test_missing_receipt_is_retryable_not_fabricated(): void
    {
        $this->fake(null);
        $this->assertNull(app(EthereumAccountingRpc::class)->receipt(self::HASH));
    }

    public function test_block_must_match_requested_height(): void
    {
        $this->fake(['number' => '0x65', 'hash' => self::BLOCK, 'timestamp' => '0x1']);
        $this->expectException(EthereumAccountingException::class);
        app(EthereumAccountingRpc::class)->block('0x64');
    }

    public function test_wrong_rpc_network_is_rejected(): void
    {
        $this->fake('0x2');
        $this->expectException(EthereumAccountingException::class);
        app(EthereumAccountingRpc::class)->assertNetwork();
    }

    private function fake(mixed $result): void
    {
        config(['services.ethereum.rpc_url' => 'https://ethereum.test']);
        Http::preventStrayRequests();
        Http::fake(['ethereum.test' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result])]);
    }

    private function receipt(): array
    {
        return ['transactionHash' => self::HASH, 'blockHash' => self::BLOCK, 'blockNumber' => '0x64', 'transactionIndex' => '0x0',
            'status' => '0x1', 'gasUsed' => '0x5208', 'effectiveGasPrice' => '0x1',
            'logs' => [['address' => '0x'.str_repeat('2', 40), 'topics' => [EthereumTransferExtractor::TOPIC, '0x'.str_repeat('0', 64), '0x'.str_repeat('0', 64)],
                'data' => '0x'.str_repeat('0', 64), 'logIndex' => '0x0', 'removed' => false]]];
    }
}
