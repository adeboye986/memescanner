<?php

namespace App\Services;

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

    public function __construct()
    {
        $this->rpcUrl = config(
            'services.solana.rpc_url',
            'https://api.mainnet-beta.solana.com'
        );
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

            if (!$tokenAccount) {
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
                'token_account' =>
                    $tokenAccount,

                'owner' =>
                    $owner,

                'amount' =>
                    $amount,

                'percentage' =>
                    round(
                        $percentage,
                        4
                    ),

                'classification' =>
                    $classification,

                'owner_program' =>
                    $ownerInfo['owner']
                        ?? null,

                'owner_executable' =>
                    (bool) (
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

            if (!$owner) {
                continue;
            }

            if (
                !isset(
                    $walletBalances[$owner]
                )
            ) {
                $walletBalances[$owner] = [
                    'owner' =>
                        $owner,

                    'amount' =>
                        0.0,

                    'percentage' =>
                        0.0,

                    'token_accounts' =>
                        [],
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
            'largest_holder_percentage' =>
                round(
                    $largestHolder,
                    4
                ),

            'top_5_percentage' =>
                round(
                    $top5,
                    4
                ),

            'top_10_percentage' =>
                round(
                    $top10,
                    4
                ),

            'wallet_count_analyzed' =>
                count($wallets),

            'excluded_account_count' =>
                count(
                    $excludedAccounts
                ),

            'wallets' =>
                $wallets,

            'excluded_accounts' =>
                $excludedAccounts,

            'raw_accounts' =>
                $rawAccounts,

            'total_supply' =>
                $totalSupply,
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

            'largest_holder_percentage' =>
                round($largest, 4),

            'top_5_percentage' =>
                round($top5, 4),

            'top_10_percentage' =>
                round($top10, 4),
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

            if (!$signature) {
                continue;
            }

            try {
                $tx = $this->getTransaction($signature);

                if (!$tx || !empty($tx['meta']['err'])) {
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

                if (!$isPumpTrade) {
                    continue;
                }

                $keys =
                    $tx['transaction']['message']['accountKeys']
                    ?? [];

                foreach ($keys as $key) {
                    $address = is_string($key)
                        ? $key
                        : ($key['pubkey'] ?? null);

                    if (!$address) {
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

        if (!$bondingCurve) {
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

                if (!$signature) {
                    continue;
                }

                $signaturesScanned++;

                try {
                    $tx =
                        $this->getTransaction(
                            $signature
                        );

                    if (
                        !$tx
                        || !empty($tx['meta']['err'])
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

                    if (!$isBuy && !$isSell) {
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

            if (!$before) {
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

            'bonding_curve' =>
                $bondingCurve,

            'signatures_scanned' =>
                $signaturesScanned,

            'trades_processed' =>
                $processed,

            'trades_skipped' =>
                $skipped,

            'buy_count' =>
                $buyCount,

            'sell_count' =>
                $sellCount,

            'buy_volume_sol' =>
                round($buyVolumeSol, 6),

            'sell_volume_sol' =>
                round($sellVolumeSol, 6),

            'total_volume_sol' =>
                round($totalVolumeSol, 6),

            'creator_fees_sol' =>
                round($creatorFeesSol, 6),

            'protocol_fees_sol' =>
                round($protocolFeesSol, 6),

            'total_pump_fees_sol' =>
                round($totalFeesSol, 6),

            'fee_threshold_sol' =>
                $feeThreshold,

            'status' =>
                $status,

            'history_exhausted' =>
                $historyExhausted,

            'passes_fee_heuristic' =>
                $thresholdReached,
        ];
    }
    
    /**
     * Best-effort Pump.fun developer activity analysis.
     *
     * The creator is inferred from the oldest Pump.fun transaction
     * involving the mint. We then scan newer mint transactions and
     * look for Pump.fun sell instructions signed by that wallet.
     *
     * This is an informational signal, not a scam verdict.
     */
    public function analyzePumpFunDeveloper(
        string $mint,
        int $maxSignatures = 500
    ): array {
        $allSignatures = [];
        $before = null;
        $historyExhausted = false;

        while (count($allSignatures) < $maxSignatures) {
            $remaining = $maxSignatures - count($allSignatures);
            $pageLimit = min(100, $remaining);

            $page = $this->getSignaturesForAddress(
                $mint,
                $pageLimit,
                $before
            );

            if (empty($page)) {
                $historyExhausted = true;
                break;
            }

            $allSignatures = array_merge(
                $allSignatures,
                $page
            );

            $last = end($page);
            $before = $last['signature'] ?? null;

            if (count($page) < $pageLimit) {
                $historyExhausted = true;
                break;
            }

            if (!$before) {
                break;
            }
        }

        if (empty($allSignatures)) {
            throw new RuntimeException(
                'No transaction history found for token.'
            );
        }

        /*
         * RPC returns newest -> oldest.
         * Search from the oldest transaction toward the present
         * for the Pump.fun creation transaction.
         */
        $creator = null;
        $creationSignature = null;

        foreach (array_reverse($allSignatures) as $entry) {
            $signature = $entry['signature'] ?? null;

            if (!$signature) {
                continue;
            }

            try {
                $tx = $this->getTransaction($signature);
            } catch (\Throwable $e) {
                continue;
            }

            if (!$tx || !empty($tx['meta']['err'])) {
                continue;
            }

            $logs = implode(
                "\n",
                $tx['meta']['logMessages'] ?? []
            );

            $isPumpCreation =
                str_contains($logs, 'Instruction: Create')
                || str_contains($logs, 'CreateV2')
                || str_contains($logs, 'Instruction: CreateV2');

            if (!$isPumpCreation) {
                continue;
            }

            $keys =
                $tx['transaction']['message']['accountKeys']
                ?? [];

            /*
             * Prefer a writable signer that is a normal System
             * Program wallet. This avoids treating programs/PDAs
             * as the developer.
             */
            foreach ($keys as $key) {
                if (is_string($key)) {
                    continue;
                }

                $address = $key['pubkey'] ?? null;
                $isSigner = (bool) ($key['signer'] ?? false);
                $isWritable = (bool) ($key['writable'] ?? false);

                if (!$address || !$isSigner || !$isWritable) {
                    continue;
                }

                try {
                    $info = $this->getParsedAccountInfo($address);
                } catch (\Throwable $e) {
                    continue;
                }

                if (
                    ($info['owner'] ?? null)
                    === '11111111111111111111111111111111'
                ) {
                    $creator = $address;
                    $creationSignature = $signature;
                    break 2;
                }
            }
        }

        if (!$creator) {
            return [
                'available' => false,
                'mint' => $mint,
                'creator' => null,
                'creation_signature' => null,
                'dev_sold' => null,
                'sell_count' => 0,
                'sell_signatures' => [],
                'signatures_scanned' => count($allSignatures),
                'history_exhausted' => $historyExhausted,
                'reason' => 'Unable to identify Pump.fun creator wallet.',
            ];
        }

        $sellSignatures = [];

        /*
         * Scan transactions after creation for Pump.fun sell
         * instructions where the creator wallet is a signer.
         */
        foreach ($allSignatures as $entry) {
            $signature = $entry['signature'] ?? null;

            if (!$signature || $signature === $creationSignature) {
                continue;
            }

            try {
                $tx = $this->getTransaction($signature);
            } catch (\Throwable $e) {
                continue;
            }

            if (!$tx || !empty($tx['meta']['err'])) {
                continue;
            }

            $logs = implode(
                "\n",
                $tx['meta']['logMessages'] ?? []
            );

            $isSell =
                str_contains($logs, 'SellV2')
                || str_contains($logs, 'V2SellExactInPumpFun')
                || str_contains($logs, 'Instruction: Sell');

            if (!$isSell) {
                continue;
            }

            $keys =
                $tx['transaction']['message']['accountKeys']
                ?? [];

            $creatorSigned = false;

            foreach ($keys as $key) {
                $address = is_string($key)
                    ? $key
                    : ($key['pubkey'] ?? null);

                $isSigner = is_string($key)
                    ? false
                    : (bool) ($key['signer'] ?? false);

                if (
                    $address === $creator
                    && $isSigner
                ) {
                    $creatorSigned = true;
                    break;
                }
            }

            if ($creatorSigned) {
                $sellSignatures[] = $signature;
            }
        }

        return [
            'available' => true,
            'mint' => $mint,
            'creator' => $creator,
            'creation_signature' => $creationSignature,
            'dev_sold' => !empty($sellSignatures),
            'sell_count' => count($sellSignatures),
            'sell_signatures' => array_slice(
                $sellSignatures,
                0,
                10
            ),
            'signatures_scanned' => count($allSignatures),
            'history_exhausted' => $historyExhausted,
        ];
    }

    private function classifyOwner(
        ?string $owner,
        ?array $ownerInfo
    ): string {
        if (!$owner) {
            return 'unknown';
        }

        if (!$ownerInfo) {
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
                    return $exception instanceof
                        \Illuminate\Http\Client\ConnectionException;
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

        if (!$response->successful()) {
            throw new RuntimeException(
                'Solana RPC error: '
                . $response->status()
                . ' - '
                . $response->body()
            );
        }

        $data = $response->json();

        if (
            !empty(
                $data['error']
            )
        ) {
            throw new RuntimeException(
                'Solana RPC returned error: '
                . json_encode(
                    $data['error']
                )
            );
        }

        return $data['result']
            ?? [];
    }
}