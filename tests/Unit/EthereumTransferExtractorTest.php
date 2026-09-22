<?php

namespace Tests\Unit;

use App\Exceptions\EthereumAccountingException;
use App\Services\EthereumAccountingNumbers;
use App\Services\EthereumTransferExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EthereumTransferExtractorTest extends TestCase
{
    private const TOKEN = '0x2222222222222222222222222222222222222222';

    private const WALLET = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const ROUTER = '0x3333333333333333333333333333333333333333';

    public function test_exact_netting_ignores_router_wrong_token_and_self_transfers(): void
    {
        $logs = [$this->log('0x0', '0a'), $this->log('0x1', '05'), $this->log('0x2', '03', self::WALLET, self::ROUTER),
            $this->log('0x3', 'ff', self::WALLET, self::WALLET), $this->log('0x4', '00'),
            $this->log('0x5', 'ff', self::ROUTER, self::ROUTER),
            [...$this->log('0x6', 'ff'), 'address' => self::ROUTER]];
        $result = (new EthereumTransferExtractor)->extract($logs, self::TOKEN, '0x'.str_repeat('A', 40));
        $this->assertSame('270', $result['incoming']);
        $this->assertSame('258', $result['outgoing']);
        $this->assertSame('12', $result['net']);
        $this->assertSame(5, $result['count']);
    }

    public function test_single_transfer_and_identical_duplicate_are_counted_once(): void
    {
        $log = $this->log('0x0', '01');
        $this->assertSame('1', (new EthereumTransferExtractor)->extract([$log, $log], self::TOKEN, self::WALLET)['net']);
    }

    public function test_maximum_and_wider_intermediate_totals_are_exact(): void
    {
        $logs = [$this->log('0x0', str_repeat('f', 64)), $this->log('0x1', '01'), $this->log('0x2', '01', self::WALLET, self::ROUTER)];
        $result = (new EthereumTransferExtractor)->extract($logs, self::TOKEN, self::WALLET);
        $this->assertSame(EthereumAccountingNumbers::MAX, $result['net']);
        $this->assertSame(bcadd(EthereumAccountingNumbers::MAX, '1', 0), $result['incoming']);
        $this->assertSame('0', EthereumAccountingNumbers::word('0x'.str_repeat('0', 64)));
    }

    #[DataProvider('invalidCases')]
    public function test_invalid_transfer_evidence_fails_closed(string $case): void
    {
        $logs = [$this->log('0x0', '01')];
        switch ($case) {
            case 'signature': $logs[0]['topics'][0] = '0x1234';
                break;
            case 'wrong signature': $logs[0]['topics'][0] = '0x'.str_repeat('1', 64);
                break;
            case 'topics': array_pop($logs[0]['topics']);
                break;
            case 'padding': $logs[0]['topics'][1] = '0x1'.substr($logs[0]['topics'][1], 3);
                break;
            case 'data': $logs[0]['data'] = '0x01';
                break;
            case 'oversized data': $logs[0]['data'] .= '00';
                break;
            case 'removed': $logs[0]['removed'] = true;
                break;
            case 'duplicate': $logs[] = $this->log('0x0', '02');
                break;
            case 'negative': $logs = [$this->log('0x0', '01', self::WALLET, self::ROUTER)];
                break;
            case 'zero': $logs = [$this->log('0x0', '00')];
                break;
            case 'self only': $logs = [$this->log('0x0', '01', self::WALLET, self::WALLET)];
                break;
            case 'wrong wallet': $logs = [$this->log('0x0', '01', self::ROUTER, self::ROUTER)];
                break;
            case 'overflow': $logs = [$this->log('0x0', str_repeat('f', 64)), $this->log('0x1', '01')];
                break;
        }
        $this->expectException(EthereumAccountingException::class);
        (new EthereumTransferExtractor)->extract($logs, self::TOKEN, self::WALLET);
    }

    public static function invalidCases(): array
    {
        return array_map(fn ($case) => [$case], ['signature', 'wrong signature', 'topics', 'padding', 'data', 'oversized data', 'removed', 'duplicate', 'negative', 'zero', 'self only', 'wrong wallet', 'overflow']);
    }

    private function log(string $index, string $hex, string $from = self::ROUTER, string $to = self::WALLET): array
    {
        return ['address' => self::TOKEN, 'topics' => [EthereumTransferExtractor::TOPIC, '0x'.str_repeat('0', 24).substr($from, 2), '0x'.str_repeat('0', 24).substr($to, 2)],
            'data' => '0x'.str_pad($hex, 64, '0', STR_PAD_LEFT), 'logIndex' => $index, 'removed' => false];
    }
}
