<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SolanaService
{
    private string $rpcUrl;

    /**
     * Pump.fun program ID.
     */
    private const PUMP_FUN_PROGRAM =
        '6EF8rrecthR5Dkzon8Nwu78hRvfCKubJ14M5uBEwF6P';

    public function __construct(ApplicationSettingsService $settings)
    {
        $this->rpcUrl = (string) $settings->getSecret('blockchain.solana_rpc_url');
    }

    public function getTokenLargestAccounts(string $mint): array
    {
        return $this->rpcRequest(
            'getTokenLargestAccounts',
            [
                $mint,
                [
                    'commitment' => 'confirmed',
                ],
            ]
        )['value'] ?? [];
    }

    public function getTokenSupply(string $mint): array
    {
        return $this->rpcRequest(
            'getTokenSupply',
            [
                $mint,
                [
                    'commitment' => 'confirmed',
                ],
            ]
        )['value'] ?? [];
    }

    public function getParsedAccountInfo(string $address): ?array
    {
        $result = $this->rpcRequest(
            'getAccountInfo',
            [
                $address,
                [
                    'encoding' => 'jsonParsed',
                    'commitment' => 'confirmed',
                ],
            ]
        );

        return $result['value'] ?? null;
    }

    public function getTokenAccountOwner(
        string $tokenAccount
    ): ?string {
        $info = $this->getParsedAccountInfo($tokenAccount);

        return $info['data']['parsed']['info']['owner']
            ?? null;
    }

    public function analyzeHolderConcentration(
        string $mint
    ): array {
        $largestAccounts =
            $this->getTokenLargestAccounts($mint);

        $supply =
            $this->getTokenSupply($mint);

        $totalSupply = (float) (
            $supply['uiAmountString']
            ?? $supply['uiAmount']
            ?? 0
        );

        if ($totalSupply <= 0) {
            throw new RuntimeException(
                'Unable to determine token supply.'
            );
        }

        $rawAccounts = [];
        $excludedAccounts = [];
        $walletBalances = [];

        foreach ($largestAccounts as $account) {
            $tokenAccount =
                $account['address'] ?? null;

            if (! $tokenAccount) {
                continue;
            }

            $amount = (float) (
                $account['uiAmountString']
                ?? $account['uiAmount']
                ?? 0
            );

            $percentage =
                ($amount / $totalSupply) * 100;

            $owner =
                $this->getTokenAccountOwner(
                    $tokenAccount
                );

            $ownerInfo = $owner
                ? $this->getParsedAccountInfo(
                    $owner
                )
                : null;

            $classification =
                $this->classifyOwner(
                    $owner,
                    $ownerInfo
                );

            $entry = [
                'token_account' => $tokenAccount,

                'owner' => $owner,

                'amount' => $amount,

                'percentage' => round(
                    $percentage,
                    4
                ),

                'classification' => $classification,

                'owner_program' => $ownerInfo['owner']
                        ?? null,

                'owner_executable' => (bool) (
                    $ownerInfo['executable']
                    ?? false
                ),
            ];

            $rawAccounts[] = $entry;

            if (
                $classification
                !== 'wallet'
            ) {
                $excludedAccounts[] =
                    $entry;

                continue;
            }

            if (! $owner) {
                continue;
            }

            if (
                ! isset(
                    $walletBalances[$owner]
                )
            ) {
                $walletBalances[$owner] = [
                    'owner' => $owner,

                    'amount' => 0.0,

                    'percentage' => 0.0,

                    'token_accounts' => [],
                ];
            }

            $walletBalances[$owner]['amount']
                += $amount;

            $walletBalances[$owner]['percentage']
                += $percentage;

            $walletBalances[$owner]['token_accounts'][]
                = $tokenAccount;
        }

        $wallets =
            array_values(
                $walletBalances
            );

        usort(
            $wallets,
            function (
                array $a,
                array $b
            ) {
                return
                    $b['percentage']
                    <=>
                    $a['percentage'];
            }
        );

        $wallets = array_map(
            function (array $wallet) {
                $wallet['percentage'] =
                    round(
                        $wallet['percentage'],
                        4
                    );

                return $wallet;
            },
            $wallets
        );

        $largestHolder =
            $wallets[0]['percentage']
            ?? 0;

        $top5 =
            array_sum(
                array_column(
                    array_slice(
                        $wallets,
                        0,
                        5
                    ),
                    'percentage'
                )
            );

        $top10 =
            array_sum(
                array_column(
                    array_slice(
                        $wallets,
                        0,
                        10
                    ),
                    'percentage'
                )
            );

        return [
            'largest_holder_percentage' => round(
                $largestHolder,
                4
            ),

            'top_5_percentage' => round(
                $top5,
                4
            ),

            'top_10_percentage' => round(
                $top10,
                4
            ),

            'wallet_count_analyzed' => count($wallets),

            'excluded_account_count' => count(
                $excludedAccounts
            ),

            'wallets' => $wallets,

            'excluded_accounts' => $excludedAccounts,

            'raw_accounts' => $rawAccounts,

            'total_supply' => $totalSupply,
        ];
    }

    public function evaluateHolderRisk(array $analysis): array
    {
        $largest = (float) (
            $analysis['largest_holder_percentage']
            ?? 0
        );

        $top5 = (float) (
            $analysis['top_5_percentage']
            ?? 0
        );

        $top10 = (float) (
            $analysis['top_10_percentage']
            ?? 0
        );

        $score = 100;
        $reasons = [];
        $level = 'low';
        $passed = true;

        /*
        * Largest individual real wallet.
        */
        if ($largest > 10) {
            $score -= 60;
            $level = 'critical';
            $passed = false;

            $reasons[] =
                'Largest holder controls more than 10%.';
        } elseif ($largest > 5) {
            $score -= 35;
            $level = 'high';

            $reasons[] =
                'Largest holder controls more than 5%.';
        } elseif ($largest > 3.5) {
            $score -= 15;
            $level = 'warning';

            $reasons[] =
                'Largest holder exceeds 3.5%.';
        }

        /*
        * Top 5 concentration.
        */
        if ($top5 > 30) {
            $score -= 25;

            if ($level !== 'critical') {
                $level = 'high';
            }

            $reasons[] =
                'Top 5 holders control more than 30%.';
        } elseif ($top5 > 20) {
            $score -= 10;

            if ($level === 'low') {
                $level = 'warning';
            }

            $reasons[] =
                'Top 5 holders control more than 20%.';
        }

        /*
        * Top 10 concentration.
        */
        if ($top10 > 50) {
            $score -= 25;

            if ($level !== 'critical') {
                $level = 'high';
            }

            $reasons[] =
                'Top 10 holders control more than 50%.';
        } elseif ($top10 > 35) {
            $score -= 10;

            if ($level === 'low') {
                $level = 'warning';
            }

            $reasons[] =
                'Top 10 holders control more than 35%.';
        }

        $score = max(0, min($score, 100));

        /*
        * Hard fail only for extreme concentration
        * for now.
        */
        if (
            $largest > 10
            || $top5 > 50
            || $top10 > 70
        ) {
            $passed = false;
        }

        return [
            'passed' => $passed,
            'score' => $score,
            'level' => $level,
            'reasons' => $reasons,

            'largest_holder_percentage' => round($largest, 4),

            'top_5_percentage' => round($top5, 4),

            'top_10_percentage' => round($top10, 4),
        ];
    }

    public function getTransaction(string $signature): ?array
    {
        return $this->rpcRequest(
            'getTransaction',
            [
                $signature,
                [
                    'encoding' => 'jsonParsed',
                    'commitment' => 'confirmed',
                    'maxSupportedTransactionVersion' => 0,
                ],
            ]
        );
    }

    public function findPumpFunBondingCurve(
        string $mint,
        int $searchLimit = 20
    ): ?string {
        $signatures = $this->getSignaturesForAddress(
            $mint,
            $searchLimit
        );

        foreach ($signatures as $entry) {
            $signature = $entry['signature'] ?? null;

            if (! $signature) {
                continue;
            }

            try {
                $tx = $this->getTransaction($signature);

                if (! $tx || ! empty($tx['meta']['err'])) {
                    continue;
                }

                $logs = implode(
                    "\n",
                    $tx['meta']['logMessages'] ?? []
                );

                $isPumpTrade =
                    str_contains($logs, 'BuyExactQuoteInV2')
                    || str_contains($logs, 'SellV2')
                    || str_contains($logs, 'V2SellExactInPumpFun')
                    || str_contains($logs, 'Instruction: Buy')
                    || str_contains($logs, 'Instruction: Sell');

                if (! $isPumpTrade) {
                    continue;
                }

                $keys =
                    $tx['transaction']['message']['accountKeys']
                    ?? [];

                foreach ($keys as $key) {
                    $address = is_string($key)
                        ? $key
                        : ($key['pubkey'] ?? null);

                    if (! $address) {
                        continue;
                    }

                    try {
                        $info =
                            $this->getParsedAccountInfo(
                                $address
                            );
                    } catch (\Throwable $e) {
                        continue;
                    }

                    if (
                        ($info['owner'] ?? null)
                        === self::PUMP_FUN_PROGRAM
                    ) {
                        return $address;
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return null;
    }

    public function analyzePumpFunFees(
        string $mint,
        int $maxSignatures = 500,
        float $feeThreshold = 0.5
    ): array {
        $bondingCurve =
            $this->findPumpFunBondingCurve($mint);

        if (! $bondingCurve) {
            throw new RuntimeException(
                'Unable to identify Pump.fun bonding curve.'
            );
        }

        $buyCount = 0;
        $sellCount = 0;

        $buyVolumeSol = 0.0;
        $sellVolumeSol = 0.0;

        $processed = 0;
        $skipped = 0;
        $signaturesScanned = 0;

        $before = null;
        $historyExhausted = false;
        $thresholdReached = false;

        while ($signaturesScanned < $maxSignatures) {
            $remaining =
                $maxSignatures - $signaturesScanned;

            $pageLimit =
                min(100, $remaining);

            $signatures =
                $this->getSignaturesForAddress(
                    $mint,
                    $pageLimit,
                    $before
                );

            if (empty($signatures)) {
                $historyExhausted = true;
                break;
            }

            foreach ($signatures as $entry) {
                $signature =
                    $entry['signature']
                    ?? null;

                if (! $signature) {
                    continue;
                }

                $signaturesScanned++;

                try {
                    $tx =
                        $this->getTransaction(
                            $signature
                        );

                    if (
                        ! $tx
                        || ! empty($tx['meta']['err'])
                    ) {
                        $skipped++;

                        continue;
                    }

                    $logs = implode(
                        "\n",
                        $tx['meta']['logMessages']
                            ?? []
                    );

                    $isBuy =
                        str_contains(
                            $logs,
                            'BuyExactQuoteInV2'
                        )
                        || str_contains(
                            $logs,
                            'Instruction: Buy'
                        );

                    $isSell =
                        str_contains(
                            $logs,
                            'SellV2'
                        )
                        || str_contains(
                            $logs,
                            'V2SellExactInPumpFun'
                        )
                        || str_contains(
                            $logs,
                            'Instruction: Sell'
                        );

                    if (! $isBuy && ! $isSell) {
                        $skipped++;

                        continue;
                    }

                    $keys = array_map(
                        function ($key) {
                            return is_string($key)
                                ? $key
                                : (
                                    $key['pubkey']
                                    ?? null
                                );
                        },
                        $tx['transaction']['message']['accountKeys']
                            ?? []
                    );

                    $curveIndex =
                        array_search(
                            $bondingCurve,
                            $keys,
                            true
                        );

                    if ($curveIndex === false) {
                        $skipped++;

                        continue;
                    }

                    $pre =
                        (
                            $tx['meta']['preBalances'][$curveIndex]
                            ?? 0
                        )
                        / 1_000_000_000;

                    $post =
                        (
                            $tx['meta']['postBalances'][$curveIndex]
                            ?? 0
                        )
                        / 1_000_000_000;

                    $change =
                        $post - $pre;

                    if ($isBuy && $change > 0) {
                        $buyCount++;
                        $buyVolumeSol += $change;
                        $processed++;
                    } elseif ($isSell && $change < 0) {
                        $sellCount++;
                        $sellVolumeSol += abs($change);
                        $processed++;
                    } else {
                        $skipped++;

                        continue;
                    }

                    $currentVolume =
                        $buyVolumeSol
                        + $sellVolumeSol;

                    $currentFees =
                        $currentVolume * 0.0125;

                    if (
                        $currentFees
                        >= $feeThreshold
                    ) {
                        $thresholdReached = true;
                        break 2;
                    }

                } catch (\Throwable $e) {
                    $skipped++;
                }
            }

            $last =
                end($signatures);

            $before =
                $last['signature']
                ?? null;

            if (
                count($signatures)
                < $pageLimit
            ) {
                $historyExhausted = true;
                break;
            }

            if (! $before) {
                break;
            }
        }

        $totalVolumeSol =
            $buyVolumeSol
            + $sellVolumeSol;

        $creatorFeesSol =
            $totalVolumeSol * 0.003;

        $protocolFeesSol =
            $totalVolumeSol * 0.0095;

        $totalFeesSol =
            $creatorFeesSol
            + $protocolFeesSol;

        if ($thresholdReached) {
            $status = 'passed';
        } elseif ($historyExhausted) {
            $status = 'failed';
        } else {
            $status = 'incomplete';
        }

        return [
            'mint' => $mint,

            'bonding_curve' => $bondingCurve,

            'signatures_scanned' => $signaturesScanned,

            'trades_processed' => $processed,

            'trades_skipped' => $skipped,

            'buy_count' => $buyCount,

            'sell_count' => $sellCount,

            'buy_volume_sol' => round($buyVolumeSol, 6),

            'sell_volume_sol' => round($sellVolumeSol, 6),

            'total_volume_sol' => round($totalVolumeSol, 6),

            'creator_fees_sol' => round($creatorFeesSol, 6),

            'protocol_fees_sol' => round($protocolFeesSol, 6),

            'total_pump_fees_sol' => round($totalFeesSol, 6),

            'fee_threshold_sol' => $feeThreshold,

            'status' => $status,

            'history_exhausted' => $historyExhausted,

            'passes_fee_heuristic' => $thresholdReached,
        ];
    }

    /**
     * Fetch the oldest transactions touching an address using Helius'
     * getTransactionsForAddress archival RPC.
     *
     * This avoids walking through thousands of recent signatures when
     * all we need is the mint's creation transaction.
     */
    public function getOldestTransactionsForAddress(
        string $address,
        int $limit = 40
    ): array {
        $limit = max(1, min($limit, 1000));

        $result = $this->rpcRequest(
            'getTransactionsForAddress',
            [
                $address,
                [
                    'transactionDetails' => 'signatures',
                    'sortOrder' => 'asc',
                    'limit' => $limit,
                ],
            ]
        );

        $data = $result['data'] ?? [];

        return is_array($data)
            ? $data
            : [];
    }

    /**
     * Best-effort developer activity analysis for Pump.fun tokens.
     *
     * Phase 1:
     * - paginate mint signatures toward the oldest history using cheap
     *   getSignaturesForAddress calls;
     * - inspect only a small tail of the oldest transactions to find
     *   the Pump.fun creation transaction and creator wallet.
     *
     * Phase 2:
     * - scan recent creator-wallet transactions;
     * - compare the creator's pre/post balance for this mint;
     * - classify a likely sale when the creator's token balance falls
     *   and either a sell instruction is present or native SOL rises.
     *
     * This works across bonding-curve and post-graduation activity
     * without relying only on Pump.fun Sell instruction names.
     */
    public function analyzePumpFunDeveloper(
        string $mint,
        int $creatorTxLimit = 250
    ): array {
        $creatorTxLimit =
            max(25, min($creatorTxLimit, 1000));

        /*
         * Helius archival RPC lets us ask for the oldest activity
         * directly, so we no longer need to page through 10,000+
         * recent mint signatures.
         */
        $oldestTransactions =
            $this->getOldestTransactionsForAddress(
                $mint,
                40
            );

        if (empty($oldestTransactions)) {
            throw new RuntimeException(
                'No historical transactions found for token.'
            );
        }

        $creator = null;
        $creationSignature = null;
        $creationTransactionsInspected = 0;

        foreach ($oldestTransactions as $entry) {
            $signature = $entry['signature'] ?? null;

            if (! $signature) {
                continue;
            }

            try {
                $tx = $this->getTransaction($signature);
            } catch (\Throwable $e) {
                continue;
            }

            if (! $tx || ! empty($tx['meta']['err'])) {
                continue;
            }

            $creationTransactionsInspected++;

            $logs = implode(
                "\n",
                $tx['meta']['logMessages'] ?? []
            );

            $keys =
                $tx['transaction']['message']['accountKeys']
                ?? [];

            $touchesPumpFun = false;

            foreach ($keys as $key) {
                $address = is_string($key)
                    ? $key
                    : ($key['pubkey'] ?? null);

                if ($address === self::PUMP_FUN_PROGRAM) {
                    $touchesPumpFun = true;
                    break;
                }
            }

            if (! $touchesPumpFun) {
                continue;
            }

            $isPumpCreation =
                str_contains($logs, 'Instruction: Create')
                || str_contains($logs, 'CreateV2')
                || str_contains(
                    $logs,
                    'Instruction: CreateV2'
                );

            if (! $isPumpCreation) {
                continue;
            }

            /*
             * Pump.fun creation has one or more writable signers.
             * Prefer a normal System Program wallet and skip the mint.
             */
            foreach ($keys as $key) {
                if (is_string($key)) {
                    continue;
                }

                $address = $key['pubkey'] ?? null;
                $isSigner =
                    (bool) ($key['signer'] ?? false);
                $isWritable =
                    (bool) ($key['writable'] ?? false);

                if (
                    ! $address
                    || $address === $mint
                    || ! $isSigner
                    || ! $isWritable
                ) {
                    continue;
                }

                try {
                    $info =
                        $this->getParsedAccountInfo(
                            $address
                        );
                } catch (\Throwable $e) {
                    continue;
                }

                if (
                    ($info['owner'] ?? null)
                    ===
                    '11111111111111111111111111111111'
                ) {
                    $creator = $address;
                    $creationSignature = $signature;
                    break 2;
                }
            }
        }

        if (! $creator) {
            return [
                'available' => false,
                'mint' => $mint,
                'creator' => null,
                'creation_signature' => null,
                'dev_sold' => null,
                'dev_token_outflow' => null,
                'sell_count' => 0,
                'outflow_count' => 0,
                'sell_signatures' => [],
                'outflow_signatures' => [],
                'oldest_transactions_returned' => count($oldestTransactions),
                'creation_transactions_inspected' => $creationTransactionsInspected,
                'creator_transactions_scanned' => 0,
                'reason' => 'Unable to identify Pump.fun creator wallet from oldest transactions.',
            ];
        }

        /*
         * Once the creator is known, scan only the creator wallet's
         * recent activity instead of every transaction touching mint.
         */
        $creatorSignatures =
            $this->getSignaturesForAddress(
                $creator,
                $creatorTxLimit
            );

        $sellEvents = [];
        $outflowEvents = [];
        $creatorTransactionsScanned = 0;

        foreach ($creatorSignatures as $entry) {
            $signature = $entry['signature'] ?? null;

            if (
                ! $signature
                || $signature === $creationSignature
            ) {
                continue;
            }

            try {
                $tx = $this->getTransaction($signature);
            } catch (\Throwable $e) {
                continue;
            }

            if (! $tx || ! empty($tx['meta']['err'])) {
                continue;
            }

            $creatorTransactionsScanned++;

            $preToken =
                $this->sumOwnerMintTokenBalance(
                    $tx['meta']['preTokenBalances'] ?? [],
                    $creator,
                    $mint
                );

            $postToken =
                $this->sumOwnerMintTokenBalance(
                    $tx['meta']['postTokenBalances'] ?? [],
                    $creator,
                    $mint
                );

            if ($preToken === null && $postToken === null) {
                continue;
            }

            $preToken = $preToken ?? 0.0;
            $postToken = $postToken ?? 0.0;
            $tokenDelta = $postToken - $preToken;

            if ($tokenDelta >= -0.000000001) {
                continue;
            }

            $keys =
                $tx['transaction']['message']['accountKeys']
                ?? [];

            $creatorIndex = null;
            $creatorSigned = false;

            foreach ($keys as $index => $key) {
                $address = is_string($key)
                    ? $key
                    : ($key['pubkey'] ?? null);

                if ($address !== $creator) {
                    continue;
                }

                $creatorIndex = $index;

                $creatorSigned = is_string($key)
                    ? ($index === 0)
                    : (bool) ($key['signer'] ?? false);

                break;
            }

            if (! $creatorSigned) {
                continue;
            }

            $preLamports =
                $creatorIndex !== null
                    ? (
                        $tx['meta']['preBalances'][$creatorIndex]
                        ?? null
                    )
                    : null;

            $postLamports =
                $creatorIndex !== null
                    ? (
                        $tx['meta']['postBalances'][$creatorIndex]
                        ?? null
                    )
                    : null;

            $solDelta = null;

            if (
                $preLamports !== null
                && $postLamports !== null
            ) {
                $solDelta =
                    (
                        (float) $postLamports
                        - (float) $preLamports
                    )
                    / 1_000_000_000;
            }

            $logs = implode(
                "\n",
                $tx['meta']['logMessages'] ?? []
            );

            $hasSellInstruction =
                str_contains($logs, 'SellV2')
                || str_contains(
                    $logs,
                    'V2SellExactInPumpFun'
                )
                || str_contains(
                    $logs,
                    'Instruction: Sell'
                );

            $event = [
                'signature' => $signature,
                'token_amount_before' => round($preToken, 6),
                'token_amount_after' => round($postToken, 6),
                'token_delta' => round($tokenDelta, 6),
                'sol_delta' => $solDelta !== null
                        ? round($solDelta, 9)
                        : null,
                'sell_instruction' => $hasSellInstruction,
            ];

            $outflowEvents[] = $event;

            if (
                $hasSellInstruction
                || ($solDelta !== null && $solDelta > 0)
            ) {
                $sellEvents[] = $event;
            }
        }

        $currentDevBalance =
            $this->getOwnerMintBalance(
                $creator,
                $mint
            );

        $supplyData = $this->getTokenSupply($mint);

        $totalSupply = (float) (
            $supplyData['uiAmountString']
            ?? $supplyData['uiAmount']
            ?? 0
        );

        $currentDevPercentage = null;

        if (
            $currentDevBalance !== null
            && $totalSupply > 0
        ) {
            $currentDevPercentage =
                ($currentDevBalance / $totalSupply) * 100;
        }

        return [
            'available' => true,
            'mint' => $mint,
            'creator' => $creator,
            'creation_signature' => $creationSignature,
            'dev_sold' => ! empty($sellEvents),
            'dev_token_outflow' => ! empty($outflowEvents),
            'sell_count' => count($sellEvents),
            'outflow_count' => count($outflowEvents),

            'sell_signatures' => array_values(
                array_column(
                    array_slice($sellEvents, 0, 10),
                    'signature'
                )
            ),

            'outflow_signatures' => array_values(
                array_column(
                    array_slice($outflowEvents, 0, 10),
                    'signature'
                )
            ),

            'sell_events' => array_slice($sellEvents, 0, 10),

            'outflow_events' => array_slice($outflowEvents, 0, 10),

            'current_dev_token_balance' => $currentDevBalance !== null
                    ? round($currentDevBalance, 6)
                    : null,

            'current_dev_percentage' => $currentDevPercentage !== null
                    ? round($currentDevPercentage, 4)
                    : null,

            'oldest_transactions_returned' => count($oldestTransactions),

            'creation_transactions_inspected' => $creationTransactionsInspected,

            'creator_transactions_scanned' => $creatorTransactionsScanned,

            'creator_tx_limit' => $creatorTxLimit,

            /*
             * false means we inspected only the most recent creator
             * transactions, so "Dev Sold: NO" should be read as
             * "no sale detected in scanned history".
             */
            'scan_complete_for_creator' => count($creatorSignatures)
                < $creatorTxLimit,
        ];
    }

    /**
     * Sum token balance entries belonging to one owner/mint.
     *
     * Returns null when that owner/mint does not appear in the balance
     * array, which lets callers distinguish "zero" from "not present".
     */
    private function sumOwnerMintTokenBalance(
        array $balances,
        string $owner,
        string $mint
    ): ?float {
        $found = false;
        $total = 0.0;

        foreach ($balances as $balance) {
            if (
                ($balance['owner'] ?? null) !== $owner
                || ($balance['mint'] ?? null) !== $mint
            ) {
                continue;
            }

            $found = true;

            $ui =
                $balance['uiTokenAmount']
                ?? [];

            $total += (float) (
                $ui['uiAmountString']
                ?? $ui['uiAmount']
                ?? 0
            );
        }

        return $found ? $total : null;
    }

    /**
     * Current balance of one mint across all token accounts owned by
     * the developer wallet.
     */
    public function getOwnerMintBalance(
        string $owner,
        string $mint
    ): ?float {
        $result = $this->rpcRequest(
            'getTokenAccountsByOwner',
            [
                $owner,
                [
                    'mint' => $mint,
                ],
                [
                    'encoding' => 'jsonParsed',
                    'commitment' => 'confirmed',
                ],
            ]
        );

        $accounts = $result['value'] ?? [];

        if (! is_array($accounts)) {
            return null;
        }

        $total = 0.0;

        foreach ($accounts as $account) {
            $amount =
                $account['account']['data']['parsed']['info']['tokenAmount']
                ?? [];

            $total += (float) (
                $amount['uiAmountString']
                ?? $amount['uiAmount']
                ?? 0
            );
        }

        return $total;
    }

    private function classifyOwner(
        ?string $owner,
        ?array $ownerInfo
    ): string {
        if (! $owner) {
            return 'unknown';
        }

        if (! $ownerInfo) {
            return 'unknown';
        }

        $accountProgram =
            $ownerInfo['owner']
            ?? null;

        $isExecutable =
            (bool) (
                $ownerInfo['executable']
                ?? false
            );

        if ($isExecutable) {
            return 'program';
        }

        if (
            $accountProgram
            === self::PUMP_FUN_PROGRAM
        ) {
            return 'pump_fun_bonding_curve';
        }

        /*
         * Normal Solana wallets are owned
         * by the System Program.
         */
        if (
            $accountProgram
            ===
            '11111111111111111111111111111111'
        ) {
            return 'wallet';
        }

        return 'special_account';
    }

    public function getSignaturesForAddress(
        string $address,
        int $limit = 100,
        ?string $before = null
    ): array {
        $options = [
            'limit' => min(max($limit, 1), 1000),
            'commitment' => 'confirmed',
        ];

        if ($before) {
            $options['before'] = $before;
        }

        return $this->rpcRequest(
            'getSignaturesForAddress',
            [
                $address,
                $options,
            ]
        );
    }

    private function rpcRequest(
        string $method,
        array $params = []
    ): array {
        $response = Http::connectTimeout(10)
            ->timeout(30)
            ->retry(
                3,
                750,
                function ($exception) {
                    return $exception instanceof ConnectionException;
                }
            )
            ->acceptJson()
            ->post(
                $this->rpcUrl,
                [
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => $method,
                    'params' => $params,
                ]
            );

        if (! $response->successful()) {
            throw new RuntimeException(
                'Solana RPC error: '
                .$response->status()
                .' - '
                .$response->body()
            );
        }

        $data = $response->json();

        if (
            ! empty(
                $data['error']
            )
        ) {
            throw new RuntimeException(
                'Solana RPC returned error: '
                .json_encode(
                    $data['error']
                )
            );
        }

        return $data['result']
            ?? [];
    }
}
