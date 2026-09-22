<?php

namespace App\Services;

use App\Exceptions\EthereumAccountingException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Strict accounting contract, independent of the legacy receipt summary contract. */
class EthereumAccountingRpc
{
    public const MAX_BYTES = 2097152;

    public const MAX_LOGS = 1024;

    public function assertNetwork(): void
    {
        if (EthereumAccountingNumbers::quantity($this->request('eth_chainId', [])) !== '1') {
            throw new EthereumAccountingException('wrong_network', 'discrepancy');
        }
    }

    /** @return array<string, mixed>|null */
    public function receipt(string $hash): ?array
    {
        $hash = EthereumAccountingNumbers::hash($hash);
        $receipt = $this->request('eth_getTransactionReceipt', [$hash]);
        if ($receipt === null) {
            return null;
        }
        if (! is_array($receipt)) {
            throw new EthereumAccountingException('malformed_receipt', 'discrepancy');
        }
        $transaction = EthereumAccountingNumbers::hash($receipt['transactionHash'] ?? null);
        $blockHash = EthereumAccountingNumbers::hash($receipt['blockHash'] ?? null);
        $block = EthereumAccountingNumbers::quantity($receipt['blockNumber'] ?? null);
        $index = EthereumAccountingNumbers::quantity($receipt['transactionIndex'] ?? null);
        $status = EthereumAccountingNumbers::quantity($receipt['status'] ?? null);
        $gas = EthereumAccountingNumbers::quantity($receipt['gasUsed'] ?? null);
        $price = EthereumAccountingNumbers::quantity($receipt['effectiveGasPrice'] ?? null);
        if ($transaction !== $hash || ! in_array($status, ['0', '1'], true)) {
            throw new EthereumAccountingException('receipt_identity', 'discrepancy');
        }
        $logs = $receipt['logs'] ?? null;
        if (! is_array($logs) || ! array_is_list($logs)) {
            throw new EthereumAccountingException('malformed_logs', 'discrepancy');
        }
        if (count($logs) > self::MAX_LOGS) {
            throw new EthereumAccountingException('log_limit', 'unsupported');
        }
        $normalized = [];
        foreach ($logs as $log) {
            if (! is_array($log) || (array_key_exists('removed', $log) && $log['removed'] !== false)) {
                throw new EthereumAccountingException('removed_or_malformed_log', 'discrepancy');
            }
            foreach (['transactionHash' => $hash, 'blockHash' => $blockHash] as $key => $expected) {
                if (array_key_exists($key, $log) && EthereumAccountingNumbers::hash($log[$key]) !== $expected) {
                    throw new EthereumAccountingException('log_identity', 'discrepancy');
                }
            }
            foreach (['blockNumber' => $block, 'transactionIndex' => $index] as $key => $expected) {
                if (array_key_exists($key, $log) && EthereumAccountingNumbers::quantity($log[$key]) !== $expected) {
                    throw new EthereumAccountingException('log_identity', 'discrepancy');
                }
            }
            $topics = $log['topics'] ?? null;
            if (! is_array($topics) || ! array_is_list($topics) || count($topics) > 4) {
                throw new EthereumAccountingException('malformed_topics', 'discrepancy');
            }
            $logIndex = EthereumAccountingNumbers::quantity($log['logIndex'] ?? null);
            $entry = ['address' => EthereumAccountingNumbers::address($log['address'] ?? null),
                'topics' => array_map(EthereumAccountingNumbers::hash(...), $topics),
                'data' => $this->bytes($log['data'] ?? null, 65536), 'logIndex' => strtolower($log['logIndex']), 'removed' => false];
            if (isset($normalized[$logIndex]) && $normalized[$logIndex] !== $entry) {
                throw new EthereumAccountingException('conflicting_log_index', 'discrepancy');
            }
            $normalized[$logIndex] = $entry;
        }

        return ['transaction_hash' => $hash, 'block_hash' => $blockHash, 'block_number' => $block,
            'block_tag' => strtolower($receipt['blockNumber']), 'transaction_index' => $index, 'status' => $status,
            'gas_used' => $gas, 'effective_gas_price_wei' => $price, 'network_fee_wei' => bcmul($gas, $price, 0),
            'logs' => array_values($normalized)];
    }

    /** @return array<string, string> */
    public function transaction(string $hash): array
    {
        $hash = EthereumAccountingNumbers::hash($hash);
        $tx = $this->request('eth_getTransactionByHash', [$hash]);
        if ($tx === null) {
            throw new EthereumAccountingException('transaction_unavailable');
        }
        if (! is_array($tx)) {
            throw new EthereumAccountingException('malformed_transaction', 'discrepancy');
        }
        $result = ['hash' => EthereumAccountingNumbers::hash($tx['hash'] ?? null),
            'from' => EthereumAccountingNumbers::address($tx['from'] ?? null), 'to' => EthereumAccountingNumbers::address($tx['to'] ?? null),
            'value' => EthereumAccountingNumbers::quantity($tx['value'] ?? null), 'input' => $this->bytes($tx['input'] ?? null, 262144),
            'block_hash' => EthereumAccountingNumbers::hash($tx['blockHash'] ?? null),
            'block_number' => EthereumAccountingNumbers::quantity($tx['blockNumber'] ?? null),
            'transaction_index' => EthereumAccountingNumbers::quantity($tx['transactionIndex'] ?? null),
            'type' => EthereumAccountingNumbers::quantity($tx['type'] ?? '0x0')];
        if ($result['hash'] !== $hash || (isset($tx['chainId']) && EthereumAccountingNumbers::quantity($tx['chainId']) !== '1')) {
            throw new EthereumAccountingException('transaction_identity', 'discrepancy');
        }
        if (! in_array($result['type'], ['0', '1', '2'], true)) {
            throw new EthereumAccountingException('transaction_type_unsupported', 'unsupported');
        }

        return $result;
    }

    /** @return array{number: string, hash: string, timestamp: string} */
    public function block(string $tag): array
    {
        if (! in_array($tag, ['latest', 'finalized'], true)) {
            EthereumAccountingNumbers::quantity($tag);
        }
        $block = $this->request('eth_getBlockByNumber', [$tag, false]);
        if ($block === null) {
            throw new EthereumAccountingException('block_unavailable');
        }
        if (! is_array($block)) {
            throw new EthereumAccountingException('malformed_block', 'discrepancy');
        }
        $number = EthereumAccountingNumbers::quantity($block['number'] ?? null);
        if (str_starts_with($tag, '0x') && $number !== EthereumAccountingNumbers::quantity($tag)) {
            throw new EthereumAccountingException('block_identity', 'discrepancy');
        }

        return ['number' => $number, 'hash' => EthereumAccountingNumbers::hash($block['hash'] ?? null),
            'timestamp' => EthereumAccountingNumbers::quantity($block['timestamp'] ?? null)];
    }

    public function codeHash(string $token, string $blockHash): string
    {
        $code = $this->bytes($this->request('eth_getCode', [EthereumAccountingNumbers::address($token),
            ['blockHash' => EthereumAccountingNumbers::hash($blockHash), 'requireCanonical' => true]]), 262144);
        if ($code === '0x') {
            throw new EthereumAccountingException('contract_code_missing', 'discrepancy');
        }

        return hash('sha256', hex2bin(substr($code, 2)));
    }

    public function decimals(string $token, string $blockHash): int
    {
        $value = EthereumAccountingNumbers::word($this->request('eth_call', [
            ['to' => EthereumAccountingNumbers::address($token), 'data' => '0x313ce567'],
            ['blockHash' => EthereumAccountingNumbers::hash($blockHash), 'requireCanonical' => true],
        ]));
        if (bccomp($value, '18', 0) > 0) {
            throw new EthereumAccountingException('decimals_outside_policy', 'unsupported');
        }

        return (int) $value;
    }

    private function bytes(mixed $value, int $limit): string
    {
        if (! is_string($value) || strlen($value) > $limit + 2 || preg_match('/^0x(?:[a-fA-F0-9]{2})*$/D', $value) !== 1) {
            throw new EthereumAccountingException('malformed_or_oversized_bytes', 'discrepancy');
        }

        return strtolower($value);
    }

    /** @param list<mixed> $params */
    private function request(string $method, array $params): mixed
    {
        $url = (string) config('services.ethereum.rpc_url');
        if (! str_starts_with($url, 'https://')) {
            throw new EthereumAccountingException('rpc_not_configured');
        }
        try {
            $response = Http::acceptJson()->asJson()->connectTimeout(3)->timeout(8)->withOptions([
                'decode_content' => false,
                'progress' => function ($total, $downloaded): void {
                    if ($total > self::MAX_BYTES || $downloaded > self::MAX_BYTES) {
                        throw new EthereumAccountingException('response_limit', 'unsupported');
                    }
                },
            ])->post($url, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
        } catch (ConnectionException) {
            throw new EthereumAccountingException('rpc_unavailable');
        }
        if (strlen($response->body()) > self::MAX_BYTES) {
            throw new EthereumAccountingException('response_limit', 'unsupported');
        }
        if (! $response->successful()) {
            throw new EthereumAccountingException('rpc_unavailable');
        }
        $body = $response->json();
        if (! is_array($body) || ($body['jsonrpc'] ?? null) !== '2.0' || ($body['id'] ?? null) !== 1) {
            throw new EthereumAccountingException('rpc_envelope', 'discrepancy');
        }
        if (isset($body['error'])) {
            throw new EthereumAccountingException('rpc_capability_unavailable');
        }
        if (! array_key_exists('result', $body)) {
            throw new EthereumAccountingException('rpc_envelope', 'discrepancy');
        }

        return $body['result'];
    }
}
