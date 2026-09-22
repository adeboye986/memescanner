<?php

namespace App\Services;

use App\Exceptions\EthereumAccountingException;

class EthereumTransferExtractor
{
    public const VERSION = 'erc20-wallet-net-v1';

    public const TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    /** @param list<array<string, mixed>> $logs
     * @return array{incoming: string, outgoing: string, net: string, count: int, transfers: array}
     */
    public function extract(array $logs, string $token, string $wallet): array
    {
        $token = EthereumAccountingNumbers::address($token);
        $wallet = EthereumAccountingNumbers::address($wallet);
        if (count($logs) > EthereumAccountingRpc::MAX_LOGS) {
            throw new EthereumAccountingException('log_limit', 'unsupported');
        }
        $incoming = '0';
        $outgoing = '0';
        $transfers = [];
        $seen = [];
        foreach ($logs as $log) {
            $index = EthereumAccountingNumbers::quantity($log['logIndex'] ?? null);
            if ((array_key_exists('removed', $log) && $log['removed'] !== false)) {
                throw new EthereumAccountingException('removed_log', 'discrepancy');
            }
            $address = EthereumAccountingNumbers::address($log['address'] ?? null);
            if (isset($seen[$index])) {
                if ($seen[$index] !== $log) {
                    throw new EthereumAccountingException('conflicting_log_index', 'discrepancy');
                }

                continue;
            }
            $seen[$index] = $log;
            if ($address !== $token) {
                continue;
            }
            $topics = $log['topics'] ?? null;
            if (! is_array($topics) || ! array_is_list($topics) || count($topics) < 1) {
                throw new EthereumAccountingException('malformed_transfer_topics', 'discrepancy');
            }
            $signature = EthereumAccountingNumbers::hash($topics[0]);
            if ($signature !== self::TOPIC) {
                continue;
            }
            if (count($topics) !== 3) {
                throw new EthereumAccountingException('malformed_transfer_topics', 'discrepancy');
            }
            $from = $this->indexedAddress($topics[1]);
            $to = $this->indexedAddress($topics[2]);
            $amount = EthereumAccountingNumbers::word($log['data'] ?? null);
            if ($from !== $wallet && $to !== $wallet) {
                continue;
            }
            if ($to === $wallet) {
                $incoming = bcadd($incoming, $amount, 0);
            }
            if ($from === $wallet) {
                $outgoing = bcadd($outgoing, $amount, 0);
            }
            $transfers[] = ['log_index' => $index, 'from' => $from, 'to' => $to, 'amount' => $amount];
        }
        usort($transfers, fn (array $a, array $b): int => bccomp($a['log_index'], $b['log_index'], 0));
        $net = bcsub($incoming, $outgoing, 0);
        if (bccomp($net, '0', 0) <= 0 || bccomp($net, EthereumAccountingNumbers::MAX, 0) > 0) {
            throw new EthereumAccountingException('invalid_net_acquisition', 'discrepancy');
        }

        return ['incoming' => $incoming, 'outgoing' => $outgoing, 'net' => $net, 'count' => count($transfers), 'transfers' => $transfers];
    }

    private function indexedAddress(mixed $topic): string
    {
        $topic = EthereumAccountingNumbers::hash($topic);
        if (substr($topic, 2, 24) !== str_repeat('0', 24)) {
            throw new EthereumAccountingException('invalid_address_padding', 'discrepancy');
        }

        return EthereumAccountingNumbers::address('0x'.substr($topic, 26));
    }
}
