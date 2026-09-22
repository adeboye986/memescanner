<?php

namespace App\Services;

use App\Exceptions\EthereumAccountingException;

class EthereumAccountingFinality
{
    public function __construct(private EthereumAccountingRpc $rpc) {}

    /** @param array<string, mixed> $receipt
     * @return array<string, mixed>
     */
    public function observe(array $receipt): array
    {
        $mode = config('services.ethereum.accounting.finality', 'finalized');
        $depth = config('services.ethereum.accounting.confirmations');
        $policy = ['mode' => $mode, 'version' => 'ethereum-finality-v1', 'required_depth' => $mode === 'confirmations' ? $depth : null];
        if (! in_array($mode, ['finalized', 'confirmations'], true)
            || ($mode === 'confirmations' && (! is_string($depth) || preg_match('/^[1-9][0-9]{0,8}$/D', $depth) !== 1))) {
            return [...$policy, 'satisfied' => false, 'reason' => 'finality_not_configured'];
        }
        try {
            $head = $this->rpc->block($mode === 'finalized' ? 'finalized' : 'latest');
        } catch (EthereumAccountingException $exception) {
            if ($exception->state !== 'pending') {
                throw $exception;
            }

            return [...$policy, 'satisfied' => false, 'reason' => 'finality_unavailable'];
        }
        if ($head['number'] === $receipt['block_number'] && $head['hash'] !== $receipt['block_hash']) {
            throw new EthereumAccountingException('finality_block_conflict', 'discrepancy');
        }
        $confirmations = bcadd(bcsub($head['number'], $receipt['block_number'], 0), '1', 0);
        $satisfied = $mode === 'finalized'
            ? bccomp($head['number'], $receipt['block_number'], 0) >= 0
            : bccomp($confirmations, $depth, 0) >= 0;

        return [...$policy, 'head' => $head, 'satisfied' => $satisfied, 'reason' => $satisfied ? null : 'finality_insufficient'];
    }
}
