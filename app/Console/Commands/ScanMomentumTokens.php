<?php

namespace App\Console\Commands;

use App\Chain;
use App\Models\TokenScan;
use App\Models\TokenScanHistory;
use App\Services\BirdeyeService;
use App\Services\DexScreenerService;
use App\Services\EthereumScannerService;
use App\Services\GoPlusService;
use App\Services\PaperTradeEntryService;
use App\Services\PaperWalletService;
use App\Services\SolanaService;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class ScanMomentumTokens extends Command
{
    protected $signature = 'tokens:momentum
                        {--chain=solana : Blockchain to scan (solana or ethereum)}
                        {--dry-run : Run DexScreener discovery/ranking without Birdeye, GoPlus, database writes, or Telegram}';

    protected $description = 'Scan supported-chain tokens showing early momentum';

    public function handle(
        BirdeyeService $birdeye,
        GoPlusService $goplus,
        DexScreenerService $dexscreener,
        TelegramService $telegram,
        SolanaService $solana,
        PaperTradeEntryService $paperTrading,
        PaperWalletService $wallets,
        EthereumScannerService $ethereumScanner,
    ): int {
        try {
            $chain = Chain::fromInput($this->option('chain'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($chain === Chain::Ethereum) {
            $this->warn('Ethereum security status: Solana-specific Birdeye, GoPlus, holder, developer-sale, and Pump.fun checks are unavailable and are not reported as passed.');
            $result = $ethereumScanner->scan('momentum');
            $this->info(sprintf('Ethereum momentum scan finished: %d profiles, %d qualified, %d paper buys.', $result['profiles'], $result['qualified'], $result['positions']));

            return self::SUCCESS;
        }

        $this->info('Fetching DexScreener momentum candidates...');

        try {
            $profiles = $dexscreener->latestSolanaProfiles(30);
        } catch (\Throwable $e) {
            $this->error(
                'Dex discovery failed: '.$e->getMessage()
            );

            return self::FAILURE;
        }

        if (empty($profiles)) {
            $this->warn('No Solana DexScreener profiles returned.');

            return self::SUCCESS;
        }

        $this->info(
            'Found '.count($profiles).' Solana profiles.'
        );

        /*
         * Temporary Birdeye protection.
         *
         * We first evaluate and rank every DexScreener survivor for free.
         * Only the strongest candidate(s) are allowed to consume Birdeye
         * tokenOverview() requests.
         */
        $birdeyeOverviewBudget = max(
            0,
            (int) config('services.birdeye.momentum_budget', 1)
        );

        $paperTradingEnabled = (bool) config(
            'services.trading.paper_trading',
            true
        );

        $fastPaperAlertsEnabled = (bool) config(
            'services.trading.fast_paper_alerts',
            true
        );

        $maxChasePercent = max(
            0,
            (float) config(
                'services.trading.max_chase_percent',
                35
            )
        );

        $dexCandidates = [];

        foreach ($profiles as $profile) {
            $address = $profile['tokenAddress'] ?? null;

            if (! $address) {
                continue;
            }

            /*
             * Get market/activity data from DexScreener before
             * spending any Birdeye compute units.
             */
            try {
                $discoveryDex = $dexscreener->analyzeToken($address);
            } catch (\Throwable $e) {
                $this->line(
                    'DEX DISCOVERY SKIP: '.
                    $address.
                    ' | '.
                    $e->getMessage()
                );

                continue;
            }

            if (! ($discoveryDex['available'] ?? false)) {
                continue;
            }

            /*
             * Token-specific Dex metrics are only trusted when
             * the requested token is the selected pair's base token.
             */
            if (! ($discoveryDex['requested_token_is_base'] ?? false)) {
                $this->line(
                    'DEX DISCOVERY SKIP: '.
                    $address.
                    ' | token is not base'
                );

                continue;
            }

            $marketCap = (float) ($discoveryDex['market_cap'] ?? 0);
            $liquidity = $discoveryDex['liquidity_usd'] ?? null;
            $volume5m = (float) ($discoveryDex['volume_5m'] ?? 0);
            $buys5m = (int) ($discoveryDex['buys_5m'] ?? 0);
            $sells5m = (int) ($discoveryDex['sells_5m'] ?? 0);
            $trades5m = $buys5m + $sells5m;
            $priceChange5m = $discoveryDex['price_change_5m'] ?? null;

            /*
             * Cheap Dex-only discovery filters. Candidates failing
             * here consume zero Birdeye overview requests.
             */
            if (
                $marketCap < 5000 ||
                $marketCap > 100000
            ) {
                continue;
            }

            if (
                $liquidity !== null &&
                (float) $liquidity < 1000
            ) {
                continue;
            }

            if ($volume5m < 500) {
                continue;
            }

            if ($trades5m < 10) {
                continue;
            }

            if (
                $priceChange5m !== null &&
                (
                    (float) $priceChange5m <= -40 ||
                    (float) $priceChange5m > 150
                )
            ) {
                continue;
            }

            $item = [
                'address' => $address,
                'symbol' => $discoveryDex['base_token_symbol'] ?? 'UNKNOWN',
                'name' => $discoveryDex['base_token_symbol'] ?? 'Unknown',
                'market_cap' => $marketCap,
                'liquidity' => $liquidity ?? 0,
                'volume_5m_usd' => $volume5m,
                'trade_5m_count' => $trades5m,
                'buy_5m_count' => $buys5m,
                'sell_5m_count' => $sells5m,
                'price_change_5m' => $priceChange5m,
                'discovery_market_cap' => $marketCap,
                'discovered_at' => now()->toIso8601String(),
                'discovery_source' => 'dexscreener',
                'profile' => $profile,
                'dex' => $discoveryDex,
            ];

            $symbol = $item['symbol'];
            $name = $item['name'];

            /*
             * Don't send a fresh momentum-discovery alert for
             * something that is already actively tracked.
             */
            $existing = TokenScan::where('chain', $chain->value)->where('address', $address)->first();

            if (
                $existing &&
                ($existing->lifecycle_status ?? 'active') === 'active'
            ) {
                $this->line(
                    "Already tracking: {$symbol}"
                );

                continue;
            }

            $dexRankScore = $this->calculateDexDiscoveryRank($item);

            $this->info(
                sprintf(
                    'DEX CANDIDATE: %s | Rank: %d/100 | MC: $%s | Liquidity: %s | Volume 5m: $%s | Trades 5m: %d',
                    $symbol,
                    $dexRankScore,
                    number_format($marketCap, 2),
                    ($liquidity !== null ? '$'.number_format((float) $liquidity, 2) : 'N/A'),
                    number_format($volume5m, 2),
                    $trades5m
                )
            );

            $dexCandidates[] = [
                'rank_score' => $dexRankScore,
                'address' => $address,
                'symbol' => $symbol,
                'name' => $name,
                'item' => $item,
                'profile' => $profile,
                'discovery_dex' => $discoveryDex,
                'existing' => $existing,
            ];
        }

        if (empty($dexCandidates)) {
            $this->warn('No Dex candidates survived the discovery filters.');
            $this->info('Momentum scan finished.');

            return self::SUCCESS;
        }

        /*
         * Rank all survivors before spending Birdeye CU.
         * Ties are broken by higher 5-minute volume, then more trades.
         */
        usort(
            $dexCandidates,
            function (array $a, array $b): int {
                $rankCompare = $b['rank_score'] <=> $a['rank_score'];

                if ($rankCompare !== 0) {
                    return $rankCompare;
                }

                $volumeCompare =
                    ($b['item']['volume_5m_usd'] ?? 0)
                    <=>
                    ($a['item']['volume_5m_usd'] ?? 0);

                if ($volumeCompare !== 0) {
                    return $volumeCompare;
                }

                return
                    ($b['item']['trade_5m_count'] ?? 0)
                    <=>
                    ($a['item']['trade_5m_count'] ?? 0);
            }
        );

        $this->newLine();
        $this->info('DEX RANKING:');

        foreach ($dexCandidates as $index => $candidate) {
            $candidateItem = $candidate['item'];

            $this->line(
                sprintf(
                    '#%d %s | Rank %d/100 | Vol/MC %.2f%% | Trades %d | Buys %d | Sells %d | Change %s',
                    $index + 1,
                    $candidate['symbol'],
                    $candidate['rank_score'],
                    (($candidateItem['volume_5m_usd'] ?? 0) / max(1, ($candidateItem['market_cap'] ?? 0))) * 100,
                    $candidateItem['trade_5m_count'] ?? 0,
                    $candidateItem['buy_5m_count'] ?? 0,
                    $candidateItem['sell_5m_count'] ?? 0,
                    $candidateItem['price_change_5m'] !== null
                        ? number_format((float) $candidateItem['price_change_5m'], 2).'%'
                        : 'N/A'
                )
            );
        }

        /*
         * Holder concentration check.
         *
         * This runs after Dex ranking but before Birdeye so concentrated
         * tokens can be rejected without spending Birdeye CU.
         */
        $holderCheckedCandidates = [];

        $this->newLine();
        $this->info('HOLDER CHECK:');

        foreach ($dexCandidates as $candidate) {
            $address = $candidate['address'];
            $symbol = $candidate['symbol'];

            try {
                $holderAnalysis =
                    $solana->analyzeHolderConcentration($address);

                $holderRisk =
                    $solana->evaluateHolderRisk($holderAnalysis);

                $this->line(
                    sprintf(
                        'HOLDERS: %s | Largest: %.2f%% | Top 5: %.2f%% | Top 10: %.2f%% | Risk: %s | Score: %s',
                        $symbol,
                        (float) ($holderRisk['largest_holder_percentage'] ?? 0),
                        (float) ($holderRisk['top_5_percentage'] ?? 0),
                        (float) ($holderRisk['top_10_percentage'] ?? 0),
                        strtoupper((string) ($holderRisk['level'] ?? 'unknown')),
                        $holderRisk['score'] ?? 'N/A'
                    )
                );

                if (($holderRisk['passed'] ?? null) === false) {
                    $this->warn(
                        sprintf(
                            'HOLDER REJECT: %s | %s',
                            $symbol,
                            implode(
                                '; ',
                                $holderRisk['reasons']
                                    ?? ['Holder concentration failed']
                            )
                        )
                    );

                    continue;
                }

                $candidate['holder_analysis'] =
                    $holderAnalysis;

                $candidate['holder_risk'] =
                    $holderRisk;

                $holderCheckedCandidates[] =
                    $candidate;

            } catch (\Throwable $e) {
                $this->warn(
                    sprintf(
                        'HOLDER UNAVAILABLE: %s | %s',
                        $symbol,
                        $e->getMessage()
                    )
                );

                $candidate['holder_analysis'] = null;

                $candidate['holder_risk'] = [
                    'passed' => null,
                    'score' => null,
                    'level' => 'unverified',
                    'reasons' => [
                        'Holder analysis unavailable.',
                    ],
                ];

                $holderCheckedCandidates[] =
                    $candidate;
            }
        }

        if (empty($holderCheckedCandidates)) {
            $this->warn('No candidates survived the holder concentration check.');
            $this->info('Momentum scan finished.');

            return self::SUCCESS;
        }

        /*
         * Combine Dex momentum strength with holder quality before Birdeye.
         *
         * Dex remains the primary signal (70%). Holder concentration
         * contributes 30%. Unverified holder data receives a neutral
         * fallback score of 50 rather than being treated as safe.
         *
         * Very overheated 5-minute price movement receives an extra
         * penalty before we spend Birdeye CU.
         */
        foreach ($holderCheckedCandidates as &$candidate) {
            $dexRank = (int) ($candidate['rank_score'] ?? 0);

            $holderScoreRaw =
                $candidate['holder_risk']['score'] ?? null;

            $holderScore =
                $holderScoreRaw !== null
                    ? (int) $holderScoreRaw
                    : 50;

            $priceChange5m =
                $candidate['item']['price_change_5m'] ?? null;

            $preBirdeyeScore =
                ($dexRank * 0.70) +
                ($holderScore * 0.30);

            if (
                $priceChange5m !== null &&
                (float) $priceChange5m > 100
            ) {
                $preBirdeyeScore -= 15;
            }

            $candidate['pre_birdeye_score'] =
                max(
                    0,
                    min(
                        100,
                        (int) round($preBirdeyeScore)
                    )
                );
        }

        unset($candidate);

        /*
         * Re-rank the holder survivors by the combined score.
         * Ties prefer the stronger original Dex rank, then volume.
         */
        usort(
            $holderCheckedCandidates,
            function (array $a, array $b): int {
                $scoreCompare =
                    ($b['pre_birdeye_score'] ?? 0)
                    <=>
                    ($a['pre_birdeye_score'] ?? 0);

                if ($scoreCompare !== 0) {
                    return $scoreCompare;
                }

                $dexCompare =
                    ($b['rank_score'] ?? 0)
                    <=>
                    ($a['rank_score'] ?? 0);

                if ($dexCompare !== 0) {
                    return $dexCompare;
                }

                return
                    ($b['item']['volume_5m_usd'] ?? 0)
                    <=>
                    ($a['item']['volume_5m_usd'] ?? 0);
            }
        );

        $this->newLine();
        $this->info('PRE-BIRDEYE RANKING:');

        foreach (
            $holderCheckedCandidates as $index => $candidate
        ) {
            $holderRisk =
                $candidate['holder_risk'] ?? [];

            $holderScore =
                $holderRisk['score'] ?? 'UNVERIFIED';

            $holderLevel =
                strtoupper(
                    (string) (
                        $holderRisk['level']
                        ?? 'unverified'
                    )
                );

            $priceChange5m =
                $candidate['item']['price_change_5m']
                ?? null;

            $overheated =
                $priceChange5m !== null &&
                (float) $priceChange5m > 100;

            $this->line(
                sprintf(
                    '#%d %s | Pre-Birdeye %d/100 | Dex %d | Holder %s (%s)%s',
                    $index + 1,
                    $candidate['symbol'],
                    $candidate['pre_birdeye_score'],
                    $candidate['rank_score'],
                    $holderScore,
                    $holderLevel,
                    $overheated
                        ? ' | Overheat penalty -15'
                        : ''
                )
            );
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->info(
                'DRY RUN: Dex ranking + holder checks + pre-Birdeye ranking complete; no Birdeye, GoPlus, database write, or Telegram call was made.'
            );
            $this->info('Momentum scan finished.');

            return self::SUCCESS;
        }

        $holderCheckedCandidates = array_values(
            array_filter(
                $holderCheckedCandidates,
                fn (array $candidate) => ($candidate['pre_birdeye_score'] ?? 0) >= 65
            )
        );

        if (empty($holderCheckedCandidates)) {
            $this->warn(
                'No candidates reached the minimum pre-Birdeye score of 65.'
            );

            $this->info('Momentum scan finished.');

            return self::SUCCESS;
        }
        /*
         * Only the strongest Dex-ranked candidates may enter the
         * expensive Birdeye enrichment stage.
         */
        $selectedCandidates = array_slice(
            $holderCheckedCandidates,
            0,
            $birdeyeOverviewBudget
        );

        $this->newLine();
        $this->info(
            sprintf(
                'BIRDEYE SELECTION: top %d of %d qualified candidate(s)',
                count($selectedCandidates),
                count($holderCheckedCandidates)
            )
        );

        foreach ($selectedCandidates as $candidate) {
            $address = $candidate['address'];
            $symbol = $candidate['symbol'];
            $name = $candidate['name'];
            $item = $candidate['item'];
            $existing = $candidate['existing'];
            $holderAnalysis = $candidate['holder_analysis'] ?? null;
            $holderRisk = $candidate['holder_risk'] ?? null;

            $item['holder_analysis'] = $holderAnalysis;
            $item['holder_risk'] = $holderRisk;

            $paperEntry = null;

            if ($paperTradingEnabled) {
                try {
                    $paperDex =
                        $dexscreener->analyzeToken($address);

                    $paperMarketCap =
                        (float) ($paperDex['market_cap'] ?? 0);

                    $discoveryMarketCap =
                        (float) (
                            $item['discovery_market_cap']
                            ?? $item['market_cap']
                            ?? 0
                        );

                    $paperMovePercent = null;

                    if (
                        $discoveryMarketCap > 0
                        && $paperMarketCap > 0
                    ) {
                        $paperMovePercent =
                            (
                                (
                                    $paperMarketCap
                                    - $discoveryMarketCap
                                )
                                / $discoveryMarketCap
                            ) * 100;
                    }

                    $paperStatus = 'simulated_buy';
                    $paperReason = 'Fast-entry gate passed.';

                    $paperPosition = null;
                    $paperBuyExecuted = false;

                    if (! ($paperDex['available'] ?? false)) {
                        $paperStatus = 'skipped';
                        $paperReason =
                            'Dex pair unavailable at entry.';
                    } elseif (
                        ! (
                            $paperDex[
                                'requested_token_is_base'
                            ] ?? false
                        )
                    ) {
                        $paperStatus = 'skipped';
                        $paperReason =
                            'Requested token is not base.';
                    } elseif (
                        $paperMarketCap < 5000
                        || $paperMarketCap > 100000
                    ) {
                        $paperStatus = 'skipped';
                        $paperReason =
                            'Current market cap outside entry range.';
                    } elseif (
                        $paperMovePercent !== null
                        && $paperMovePercent <= -30
                    ) {
                        $paperStatus = 'skipped_collapse';
                        $paperReason =
                            sprintf(
                                'Already collapsed %.2f%% since discovery; max allowed drop is -30.00%%.',
                                $paperMovePercent
                            );
                    } elseif (
                        $paperMovePercent !== null
                        && $paperMovePercent > $maxChasePercent
                    ) {
                        $paperStatus = 'skipped_chase';
                        $paperReason =
                            sprintf(
                                'Already moved +%.2f%% since discovery; max chase is %.2f%%.',
                                $paperMovePercent,
                                $maxChasePercent
                            );
                    }

                    $paperEntry = [
                        'enabled' => true,
                        'status' => $paperStatus,
                        'reason' => $paperReason,
                        'discovery_market_cap' => $discoveryMarketCap,
                        'entry_market_cap' => $paperMarketCap > 0
                                ? $paperMarketCap
                                : null,
                        'move_since_discovery_percent' => $paperMovePercent,
                        'max_chase_percent' => $maxChasePercent,
                        'entry_price' => $paperDex['price_usd']
                            ?? $paperDex['price']
                            ?? null,
                        'liquidity_usd' => $paperDex['liquidity_usd']
                            ?? null,
                        'pair_address' => $paperDex['pair_address']
                            ?? null,
                        'dex' => $paperDex['dex']
                            ?? null,
                        'discovered_at' => $item['discovered_at']
                            ?? null,
                        'entry_decided_at' => now()->toIso8601String(),
                    ];

                    if ($paperStatus === 'simulated_buy') {
                        try {
                            $paperPosition = $paperTrading->buy([
                                'chain' => $chain->value,
                                'address' => $address,
                                'symbol' => $symbol,
                                'name' => $name,

                                'discovery_market_cap' => $discoveryMarketCap,

                                'entry_market_cap' => $paperMarketCap,

                                'entry_price' => $paperEntry['entry_price'] ?? null,

                                'entry_liquidity' => $paperEntry['liquidity_usd'] ?? null,

                                'move_since_discovery_percent' => $paperMovePercent,

                                'meta' => [
                                    'pair_address' => $paperEntry['pair_address'] ?? null,

                                    'dex' => $paperEntry['dex'] ?? null,

                                    'source' => 'momentum_fast_paper',
                                ],
                                'scanner' => 'momentum',
                            ]);

                            $paperBuyExecuted =
                                $paperPosition->wasRecentlyCreated;

                            if ($paperBuyExecuted) {
                                $this->info(
                                    sprintf(
                                        'PAPER BUY EXECUTED: %s | %.4f SOL | Entry MC $%s',
                                        $symbol,
                                        $paperPosition->initial_investment_sol,
                                        number_format($paperMarketCap, 2)
                                    )
                                );
                            } else {
                                $paperStatus = 'already_open';
                                $paperReason =
                                    'Paper position is already open; no additional SOL invested.';

                                $this->line(
                                    "PAPER BUY SKIPPED: {$symbol} | position already open"
                                );
                            }
                        } catch (\Throwable $e) {
                            $paperStatus = 'buy_failed';
                            $paperReason = $e->getMessage();
                            $paperBuyExecuted = false;

                            $this->warn(
                                'PAPER BUY FAILED: '.
                                $symbol.
                                ' | '.
                                $e->getMessage()
                            );
                        }
                    }

                    $paperEntry['status'] = $paperStatus;
                    $paperEntry['reason'] = $paperReason;

                    $this->info(
                        sprintf(
                            'FAST PAPER: %s | %s | Discovery MC: $%s | Entry MC: %s | Move: %s',
                            $symbol,
                            strtoupper($paperStatus),
                            number_format(
                                $discoveryMarketCap,
                                2
                            ),
                            $paperMarketCap > 0
                                ? '$'.number_format(
                                    $paperMarketCap,
                                    2
                                )
                                : 'N/A',
                            $paperMovePercent !== null
                                ? sprintf(
                                    '%+.2f%%',
                                    $paperMovePercent
                                )
                                : 'N/A'
                        )
                    );

                    if (
                        $fastPaperAlertsEnabled
                        && $paperBuyExecuted
                    ) {
                        $paperWallet = $wallets->default($chain);
                        $currency = $paperWallet->currencyCode();

                        $walletAvailable =
                            $paperWallet
                                ? (float) $paperWallet->available_balance_sol
                                : null;

                        $walletInvested =
                            $paperWallet
                                ? (float) $paperWallet->invested_balance_sol
                                : null;

                        $walletText =
                            $paperWallet
                                ? "💳 <b>WALLET AFTER BUY</b>\n".
                                    'Available: <b>'.
                                    number_format($walletAvailable, 4).
                                    " {$currency}</b>\n".
                                    'Invested: <b>'.
                                    number_format($walletInvested, 4).
                                    " {$currency}</b>\n\n"
                                : '';

                        $fastMessage =
                            "🟢🟢🟢 <b>PAPER BUY EXECUTED</b> 🟢🟢🟢\n\n".
                            "💰 <b>{$symbol}</b> — {$name}\n\n".
                            "✅ <b>POSITION OPENED</b>\n".
                            '💵 <b>Bought:</b> '.
                            number_format(
                                (float) $paperPosition->initial_investment_sol,
                                4
                            ).
                            " {$currency}\n".
                            '🎯 <b>Entry MC:</b> $'.
                            number_format(
                                $paperMarketCap,
                                2
                            ).
                            "\n".
                            '🔎 <b>Discovery MC:</b> $'.
                            number_format(
                                $discoveryMarketCap,
                                2
                            ).
                            "\n".
                            '📈 <b>Entry Move:</b> '.
                            (
                                $paperMovePercent !== null
                                    ? sprintf(
                                        '%+.2f%%',
                                        $paperMovePercent
                                    )
                                    : 'N/A'
                            ).
                            "\n\n".
                            $walletText.
                            "🛡️ <b>EXIT PLAN</b>\n".
                            "🔴 Stop Loss: <b>-10% → CLOSE 100%</b>\n".
                            "⚪ +100% (2.00x): <b>ARM 2.00x FLOOR / HOLD</b>\n".
                            "🟡 +150% (2.50x): <b>INFORMATIONAL ONLY / HOLD</b>\n".
                            "🟢 +200% (3.00x): <b>UPGRADE FLOOR / HOLD</b>\n\n".
                            "🛡️ <b>PROTECTED EXIT</b>\n".
                            "A later observation at or below the active floor closes 100% at the observed fill.\n\n".
                            "❌ <b>No partial selling</b>\n\n".
                            "📍 <b>Token Address</b>\n".
                            "<code>{$address}</code>\n\n".
                            "⚠️ <b>PAPER TRADE — NO REAL {$currency} USED</b>\n".
                            '⏳ Deep security scan is still running.';

                        try {
                            $telegram->send($fastMessage);

                            $this->info(
                                "FAST PAPER TELEGRAM SENT: {$symbol}"
                            );
                        } catch (\Throwable $e) {
                            $this->warn(
                                "FAST PAPER TELEGRAM FAILED: {$symbol} | ".
                                $e->getMessage()
                            );
                        }
                    }

                } catch (\Throwable $e) {
                    $paperEntry = [
                        'enabled' => true,
                        'status' => 'unavailable',
                        'reason' => $e->getMessage(),
                        'discovery_market_cap' => (float) (
                            $item['discovery_market_cap']
                            ?? $item['market_cap']
                            ?? 0
                        ),
                        'entry_decided_at' => now()->toIso8601String(),
                    ];

                    $this->warn(
                        "FAST PAPER UNAVAILABLE: {$symbol} | ".
                        $e->getMessage()
                    );
                }
            }

            $this->info(
                sprintf(
                    'BIRDEYE SELECTED: %s | Pre-Birdeye %d/100 | Dex rank %d/100',
                    $symbol,
                    $candidate['pre_birdeye_score'] ?? 0,
                    $candidate['rank_score']
                )
            );

            try {
                usleep(1200000);

                $overviewResponse =
                    $birdeye->tokenOverview($address);

                $token =
                    $overviewResponse['data'] ?? null;

                if (! $token) {
                    $this->warn(
                        "No overview data for {$symbol}"
                    );

                    continue;
                }

            } catch (\Throwable $e) {
                $this->warn(
                    "Overview unavailable: {$symbol} | ".
                    $e->getMessage()
                );

                continue;
            }

            $overviewMarketCap =
                (float) ($token['marketCap'] ?? 0);

            $overviewLiquidity =
                (float) ($token['liquidity'] ?? 0);

            $holders =
                (int) ($token['holder'] ?? 0);

            $buys =
                (int) ($token['buy1m'] ?? 0);

            $sells =
                (int) ($token['sell1m'] ?? 0);

            $wallets =
                (int) ($token['uniqueWallet5m'] ?? 0);

            $priceChange =
                (float) (
                    $token['priceChange5mPercent'] ?? 0
                );

            $this->line(
                sprintf(
                    'OVERVIEW: %s | MC: $%s | Liquidity: $%s | Holders: %d | Buys: %d | Sells: %d | Wallets: %d | Change: %.2f%%',
                    $symbol,
                    number_format($overviewMarketCap, 2),
                    number_format($overviewLiquidity, 2),
                    $holders,
                    $buys,
                    $sells,
                    $wallets,
                    $priceChange
                )
            );

            /*
            * Momentum hard filters.
            */

            if ($holders < 10) {
                $this->warn(
                    "MOMENTUM REJECT: {$symbol} | only {$holders} holders"
                );

                continue;
            }

            if ($overviewLiquidity < 1000) {
                $this->warn(
                    "MOMENTUM REJECT: {$symbol} | liquidity $".
                    number_format($overviewLiquidity, 2)
                );

                continue;
            }

            if (
                $overviewMarketCap < 5000 ||
                $overviewMarketCap > 100000
            ) {
                $this->warn(
                    "MOMENTUM REJECT: {$symbol} | MC $".
                    number_format($overviewMarketCap, 2)
                );

                continue;
            }

            /*
            * Don't chase something already collapsing.
            */
            if ($priceChange <= -40) {
                $this->warn(
                    "MOMENTUM REJECT: {$symbol} | collapsing ".
                    number_format($priceChange, 2).'%'
                );

                continue;
            }

            /*
            * Don't chase an almost vertical candle either.
            */
            if ($priceChange > 150) {
                $this->warn(
                    "MOMENTUM REJECT: {$symbol} | overheated +".
                    number_format($priceChange, 2).'%'
                );

                continue;
            }

            $momentumScore =
                $this->calculateMomentumScore($item, $token);

            $level = match (true) {
                $momentumScore >= 80 => '🔥 STRONG MOMENTUM',
                $momentumScore >= 65 => '🟢 MOMENTUM CANDIDATE',
                $momentumScore >= 50 => '🟡 MOMENTUM WATCHLIST',
                default => '⚪ WEAK',
            };

            $this->info(
                sprintf(
                    'MOMENTUM SCORE: %s | %d/100 | %s',
                    $symbol,
                    $momentumScore,
                    $level
                )
            );

            /*
            * Ignore weak momentum.
            */
            if ($momentumScore < 50) {
                $this->line(
                    "MOMENTUM SKIP: {$symbol} | score {$momentumScore}"
                );

                $this->newLine();

                continue;
            }

            /*
            * Run GoPlus security only for momentum survivors.
            */
            $securityUnavailable = false;

            try {
                usleep(2200000);

                $security = $goplus->evaluateToken($address);

            } catch (\Throwable $e) {
                $securityUnavailable = true;

                $security = [
                    'passed' => null,
                    'score' => null,
                    'risks' => [
                        'GoPlus unavailable: '.$e->getMessage(),
                    ],
                ];

                $this->warn(
                    "SECURITY UNAVAILABLE: {$symbol} | continuing as unverified"
                );
            }

            /*
            * Treat missing GoPlus data as unavailable,
            * not as a confirmed security failure.
            */
            if ($security['passed'] === false) {
                $risks = $security['risks'] ?? [];

                if (
                    in_array(
                        'No GoPlus security data returned',
                        $risks,
                        true
                    )
                ) {
                    $securityUnavailable = true;

                    $security = [
                        'passed' => null,
                        'score' => null,
                        'risks' => [
                            'No GoPlus security data returned',
                        ],
                    ];

                    $this->warn(
                        "SECURITY UNAVAILABLE: {$symbol} | continuing as unverified"
                    );

                } else {
                    $riskText = implode(
                        ', ',
                        $risks
                    );

                    $this->warn(
                        sprintf(
                            'SECURITY REJECT: %s | %s',
                            $symbol,
                            $riskText ?: 'Security checks failed'
                        )
                    );

                    continue;
                }
            }

            if (! $securityUnavailable) {
                $this->info(
                    sprintf(
                        'SECURITY PASS: %s | Score: %s',
                        $symbol,
                        $security['score'] ?? 'N/A'
                    )
                );
            }

            /*
            * DexScreener confirmation.
            *
            * Birdeye has already provided the expensive overview.
            * DexScreener now performs the fresh confirmation so we
            * do not spend a second Birdeye overview request.
            */
            $initialMomentumScore = $momentumScore;
            $initialDexData = null;
            $dexData = null;
            $dexMarketCapChange = null;

            try {
                $initialDexData = $dexscreener->analyzeToken($address);

                if (! ($initialDexData['available'] ?? false)) {
                    $this->warn("DEX UNAVAILABLE: {$symbol} | no pair data");

                    continue;
                }

                $initialDexMarketCap = $initialDexData['market_cap'] ?? null;
                $initialDexLiquidity = $initialDexData['liquidity_usd'] ?? null;
                /*
                * If Dex explicitly reports liquidity and it is
                * effectively dead, reject the candidate.
                *
                * null is intentionally allowed because some
                * Pump.fun bonding-curve data may not expose
                * conventional pool liquidity.
                */
                if (
                    $initialDexLiquidity !== null &&
                    (float) $initialDexLiquidity < 1000
                ) {
                    $this->warn(
                        sprintf(
                            'DEX REJECT: %s | liquidity only $%s',
                            $symbol,
                            number_format(
                                (float) $initialDexLiquidity,
                                2
                            )
                        )
                    );

                    continue;
                }

                $dexBuys5m =
                    (int) ($initialDexData['buys_5m'] ?? 0);

                $dexSells5m =
                    (int) ($initialDexData['sells_5m'] ?? 0);

                $dexTrades5m =
                    $dexBuys5m + $dexSells5m;

                $dexVolume5m =
                    (float) ($initialDexData['volume_5m'] ?? 0);

                if (
                    $dexTrades5m === 0 &&
                    $dexVolume5m <= 0
                ) {
                    $this->warn(
                        "DEX REJECT: {$symbol} | no recent Dex activity"
                    );

                    continue;
                }
                $dexName = $initialDexData['dex'] ?? 'unknown';
                $pairAge = $initialDexData['pair_age_minutes'] ?? null;

                $this->info(sprintf(
                    'DEX SNAPSHOT: %s | DEX: %s | MC: %s | Liquidity: %s | Pair Age: %s min',
                    $symbol,
                    $dexName,
                    $initialDexMarketCap !== null ? '$'.number_format($initialDexMarketCap, 2) : 'N/A',
                    $initialDexLiquidity !== null ? '$'.number_format($initialDexLiquidity, 2) : 'N/A',
                    $pairAge !== null ? number_format($pairAge, 0) : 'N/A'
                ));
            } catch (\Throwable $e) {
                $this->warn("DEX UNAVAILABLE: {$symbol} | ".$e->getMessage());

                continue;
            }

            /* Reject large Birdeye/Dex market-cap disagreements. */
            $birdeyeMarketCap = (float) ($token['marketCap'] ?? 0);
            $initialDexMarketCap = (float) ($initialDexData['market_cap'] ?? 0);

            if ($birdeyeMarketCap > 0 && $initialDexMarketCap > 0) {
                $marketCapRatio = max($birdeyeMarketCap, $initialDexMarketCap)
                    / min($birdeyeMarketCap, $initialDexMarketCap);

                if ($marketCapRatio > 3) {
                    $this->warn(sprintf(
                        'MARKET DATA REJECT: %s | Birdeye MC $%s vs Dex MC $%s | %.2fx mismatch',
                        $symbol,
                        number_format($birdeyeMarketCap, 2),
                        number_format($initialDexMarketCap, 2),
                        $marketCapRatio
                    ));

                    continue;
                }
            }

            $this->line("CONFIRMING VIA DEX: {$symbol} | score {$initialMomentumScore}");
            sleep(8);

            try {
                $dexData = $dexscreener->analyzeToken($address);

                if (! ($dexData['available'] ?? false)) {
                    $this->warn("DEX CONFIRMATION FAILED: {$symbol} | pair disappeared/unavailable");

                    continue;
                }
            } catch (\Throwable $e) {
                $this->warn("DEX CONFIRMATION FAILED: {$symbol} | ".$e->getMessage());

                continue;
            }

            $freshDexMarketCap = (float) ($dexData['market_cap'] ?? 0);
            $freshDexLiquidityRaw = $dexData['liquidity_usd'] ?? null;
            $freshDexLiquidity = $freshDexLiquidityRaw !== null
                ? (float) $freshDexLiquidityRaw
                : null;

            /*
             * Reject a pair if the fresh Dex snapshot now shows
             * explicitly dead liquidity. Null is still allowed
             * because some bonding-curve pairs may not expose it.
             */
            if (
                $freshDexLiquidity !== null &&
                $freshDexLiquidity < 1000
            ) {
                $this->warn(
                    sprintf(
                        'DEX CONFIRMATION REJECT: %s | fresh liquidity only $%s',
                        $symbol,
                        number_format($freshDexLiquidity, 2)
                    )
                );

                continue;
            }

            if ($birdeyeMarketCap > 0 && $freshDexMarketCap > 0) {
                $freshMarketCapRatio = max($birdeyeMarketCap, $freshDexMarketCap)
                    / min($birdeyeMarketCap, $freshDexMarketCap);

                if ($freshMarketCapRatio > 3) {
                    $this->warn(sprintf(
                        'MARKET DATA REJECT: %s | Birdeye MC $%s vs fresh Dex MC $%s | %.2fx mismatch',
                        $symbol,
                        number_format($birdeyeMarketCap, 2),
                        number_format($freshDexMarketCap, 2),
                        $freshMarketCapRatio
                    ));

                    continue;
                }
            }

            if ($initialDexMarketCap > 0 && $freshDexMarketCap > 0) {
                $dexMarketCapChange = (($freshDexMarketCap - $initialDexMarketCap)
                    / $initialDexMarketCap) * 100;

                if ($dexMarketCapChange <= -30) {
                    $this->warn(sprintf(
                        'DEX CONFIRMATION REJECT: %s | MC collapsed %.2f%%',
                        $symbol,
                        $dexMarketCapChange
                    ));

                    continue;
                }

                if ($dexMarketCapChange >= 100) {
                    $this->warn(sprintf(
                        'DEX CONFIRMATION REJECT: %s | MC spiked +%.2f%%',
                        $symbol,
                        $dexMarketCapChange
                    ));

                    continue;
                }
            }

            /* Only compare liquidity when Dex returns it in both snapshots. */
            $initialDexLiquidityValue = $initialDexLiquidity !== null
                ? (float) $initialDexLiquidity
                : null;

            if (
                $initialDexLiquidityValue !== null &&
                $initialDexLiquidityValue > 0 &&
                $freshDexLiquidity !== null
            ) {
                $liquidityRetention = $freshDexLiquidity / $initialDexLiquidityValue;

                if ($liquidityRetention < 0.50) {
                    $this->warn(sprintf(
                        'DEX CONFIRMATION REJECT: %s | liquidity collapsed from $%s to $%s',
                        $symbol,
                        number_format($initialDexLiquidityValue, 2),
                        number_format($freshDexLiquidity, 2)
                    ));

                    continue;
                }
            }

            /* Keep the one Birdeye momentum score; Dex is the freshness gate. */
            $momentumScore = $initialMomentumScore;

            $this->info(sprintf(
                'DEX CONFIRMED: %s | score %d | Dex MC: %s | 8s MC move: %s',
                $symbol,
                $momentumScore,
                $freshDexMarketCap > 0 ? '$'.number_format($freshDexMarketCap, 2) : 'N/A',
                $dexMarketCapChange !== null ? number_format($dexMarketCapChange, 2).'%' : 'N/A'
            ));

            /*
             * DexScreener paid-order check.
             *
             * Informational signal only for now. Paying DexScreener
             * does not make a token safe and does not affect the score.
             */
            $dexPaidData = null;

            try {
                $dexPaidData = $dexscreener->paidOrders($address);

                $this->info(
                    sprintf(
                        'DEX PAID: %s | %s',
                        $symbol,
                        ($dexPaidData['dex_paid'] ?? false)
                            ? 'YES'
                            : 'NO'
                    )
                );

                if (! empty($dexPaidData['types'])) {
                    $this->line(
                        'DEX PAID TYPES: '.
                        implode(', ', $dexPaidData['types'])
                    );
                }

            } catch (\Throwable $e) {
                $this->warn(
                    "DEX PAID UNAVAILABLE: {$symbol} | ".
                    $e->getMessage()
                );
            }

            /*
             * Pump.fun on-chain activity check.
             *
             * PASS       = +5
             * FAILED     = -10
             * INCOMPLETE = no adjustment
             * UNAVAILABLE = no adjustment
             */
            $pumpFeeAnalysis = null;
            $pumpFeeAdjustment = 0;

            try {
                $this->info(
                    "PUMP ACTIVITY: {$symbol} | checking on-chain trading history..."
                );

                $pumpFeeAnalysis =
                    $solana->analyzePumpFunFees(
                        $address,
                        500,
                        0.5
                    );

                $pumpStatus =
                    $pumpFeeAnalysis['status']
                    ?? 'incomplete';

                if ($pumpStatus === 'passed') {
                    $pumpFeeAdjustment = 5;
                } elseif ($pumpStatus === 'failed') {
                    $pumpFeeAdjustment = -10;
                }

                $this->info(
                    sprintf(
                        'PUMP FEES: %s | %.4f SOL | Volume: %.2f SOL | Trades: %d | Scanned: %d',
                        strtoupper($pumpStatus),
                        (float) (
                            $pumpFeeAnalysis['total_pump_fees_sol']
                            ?? 0
                        ),
                        (float) (
                            $pumpFeeAnalysis['total_volume_sol']
                            ?? 0
                        ),
                        (int) (
                            $pumpFeeAnalysis['trades_processed']
                            ?? 0
                        ),
                        (int) (
                            $pumpFeeAnalysis['signatures_scanned']
                            ?? 0
                        )
                    )
                );

            } catch (\Throwable $e) {
                $this->warn(
                    "PUMP ACTIVITY UNAVAILABLE: {$symbol} | ".
                    $e->getMessage()
                );
            }

            $momentumScore = max(
                0,
                min(
                    100,
                    $momentumScore + $pumpFeeAdjustment
                )
            );

            if ($pumpFeeAdjustment !== 0) {
                $this->info(
                    sprintf(
                        'PUMP SCORE ADJUSTMENT: %s | %+d | Final score: %d/100',
                        $symbol,
                        $pumpFeeAdjustment,
                        $momentumScore
                    )
                );
            }

            /*
             * Developer activity check.
             *
             * Informational risk/context signal only for now.
             * It does not reject the token or alter momentum score.
             */
            $developerAnalysis = null;

            try {
                $this->info(
                    "DEV CHECK: {$symbol} | identifying creator and sell activity..."
                );

                $developerAnalysis =
                    $solana->analyzePumpFunDeveloper($address);

                if ($developerAnalysis['available'] ?? false) {
                    $devSold = (bool) (
                        $developerAnalysis['dev_sold'] ?? false
                    );

                    $devSellCount = (int) (
                        $developerAnalysis['sell_count'] ?? 0
                    );

                    $devHolding =
                        $developerAnalysis['current_dev_percentage']
                        ?? null;

                    $this->info(
                        sprintf(
                            'DEV: %s | Sold: %s | Sells: %d | Holding: %s',
                            $symbol,
                            $devSold ? 'YES' : 'NO',
                            $devSellCount,
                            $devHolding !== null
                                ? number_format((float) $devHolding, 2).'%'
                                : 'N/A'
                        )
                    );
                } else {
                    $this->warn(
                        "DEV UNAVAILABLE: {$symbol} | ".
                        ($developerAnalysis['reason']
                            ?? 'creator analysis unavailable')
                    );
                }
            } catch (\Throwable $e) {
                $this->warn(
                    "DEV UNAVAILABLE: {$symbol} | ".
                    $e->getMessage()
                );
            }

            $finalLevel = match (true) {
                $momentumScore >= 80 => 'strong',
                $momentumScore >= 65 => 'candidate',
                default => 'watchlist',
            };

            $scan = TokenScan::updateOrCreate(
                [
                    'chain' => $chain->value,
                    'address' => $address,
                ],
                [
                    'symbol' => $symbol,
                    'name' => $name,

                    'price' => $token['price'] ?? null,
                    'market_cap' => $token['marketCap'] ?? null,
                    'liquidity' => $token['liquidity'] ?? null,

                    'holders' => $token['holder'] ?? 0,

                    'volume_1m' => $token['v1m'] ?? 0,
                    'buys_1m' => $token['buy1m'] ?? 0,
                    'sells_1m' => $token['sell1m'] ?? 0,

                    'unique_wallets_5m' => $token['uniqueWallet5m'] ?? 0,

                    'price_change_5m' => $token['priceChange5mPercent'] ?? 0,

                    'score' => $momentumScore,

                    'security_score' => $securityUnavailable
                            ? null
                            : ($security['score'] ?? null),

                    'security_passed' => $securityUnavailable
                            ? false
                            : ($security['passed'] ?? false),

                    'security_risks' => $security['risks'] ?? [],

                    'raw_data' => [
                        'dex_discovery' => $item,
                        'birdeye_overview' => $token,
                        'initial_dexscreener' => $initialDexData,
                        'dexscreener' => $dexData,
                        'momentum_level' => $finalLevel,
                        'momentum_score' => $momentumScore,
                        'confirmation_source' => 'dexscreener',
                        'dex_market_cap_change_8s' => $dexMarketCapChange,
                        'security_unavailable' => $securityUnavailable,
                        'holder_analysis' => $holderAnalysis,
                        'holder_risk' => $holderRisk,
                        'pre_birdeye_score' => $candidate['pre_birdeye_score'] ?? null,
                        'paper_entry' => $paperEntry,
                        'dex_paid' => $dexPaidData,
                        'developer_analysis' => $developerAnalysis,
                        'pump_fun_activity' => $pumpFeeAnalysis,
                        'pump_fun_score_adjustment' => $pumpFeeAdjustment,
                    ],

                    'first_seen_at' => $existing?->first_seen_at ?? now(),

                    'last_scanned_at' => now(),
                ]
            );

            TokenScanHistory::create([
                'token_scan_id' => $scan->id,

                'address' => $address,
                'symbol' => $symbol,
                'name' => $name,

                'snapshot_type' => 'momentum_discovery',

                'price' => $token['price'] ?? null,

                'market_cap' => $token['marketCap'] ?? null,

                'liquidity' => $token['liquidity'] ?? null,

                'holders' => $token['holder'] ?? 0,

                'volume_1m' => $token['v1m'] ?? 0,

                'buys_1m' => $token['buy1m'] ?? 0,

                'sells_1m' => $token['sell1m'] ?? 0,

                'unique_wallets_5m' => $token['uniqueWallet5m'] ?? 0,

                'price_change_5m' => $token['priceChange5mPercent'] ?? 0,

                'score' => $momentumScore,

                'dex_available' => (bool) ($dexData['available'] ?? false),

                'dex' => $dexData['dex'] ?? null,

                'dex_pair_address' => $dexData['pair_address'] ?? null,

                'dex_market_cap' => $dexData['market_cap'] ?? null,

                'dex_liquidity' => $dexData['liquidity_usd'] ?? null,

                'dex_pair_age_minutes' => $dexData['pair_age_minutes'] ?? null,

                'raw_data' => [
                    'dex_discovery' => $item,
                    'birdeye_overview' => $token,
                    'initial_dexscreener' => $initialDexData,
                    'dexscreener' => $dexData,
                    'security' => $security,
                    'momentum_score' => $momentumScore,
                    'confirmation_source' => 'dexscreener',
                    'dex_market_cap_change_8s' => $dexMarketCapChange,
                    'holder_analysis' => $holderAnalysis,
                    'holder_risk' => $holderRisk,
                    'pre_birdeye_score' => $candidate['pre_birdeye_score'] ?? null,
                    'paper_entry' => $paperEntry,
                    'dex_paid' => $dexPaidData,
                    'developer_analysis' => $developerAnalysis,
                    'pump_fun_activity' => $pumpFeeAnalysis,
                    'pump_fun_score_adjustment' => $pumpFeeAdjustment,
                ],

                'scanned_at' => now(),
            ]);

            $this->info(
                sprintf(
                    'SAVED: %s | score %d | DEX confirmed | %s',
                    $symbol,
                    $momentumScore,
                    strtoupper($finalLevel)
                )
            );

            /*
            * Telegram alerts are only for confirmed
            * momentum candidates scoring 65 or higher.
            */
            if ($momentumScore >= 65) {

                $freshBuys =
                    (int) ($token['buy1m'] ?? 0);

                $freshSells =
                    (int) ($token['sell1m'] ?? 0);

                $freshWallets =
                    (int) ($token['uniqueWallet5m'] ?? 0);

                $freshHolders =
                    (int) ($token['holder'] ?? 0);

                $freshMarketCap =
                    (float) ($token['marketCap'] ?? 0);

                $freshLiquidity =
                    (float) ($token['liquidity'] ?? 0);

                $freshChange =
                    (float) (
                        $token['priceChange5mPercent'] ?? 0
                    );

                $alertHeading = match (true) {
                    $momentumScore >= 80 => '🔥 <b>STRONG MOMENTUM DETECTED</b>',

                    default => '🟢 <b>MOMENTUM CANDIDATE</b>',
                };

                if ($securityUnavailable) {
                    $securityText =
                        "⚠️ <b>SECURITY: UNVERIFIED</b>\n".
                        'GoPlus security data was unavailable.';
                } else {
                    $securityText =
                        '✅ <b>Security:</b> GoPlus passed'.
                        (
                            isset($security['score'])
                                ? " ({$security['score']}/100)"
                                : ''
                        );
                }

                $dexText = 'N/A';

                if ($dexData['available'] ?? false) {
                    $dexText =
                        strtoupper(
                            $dexData['dex'] ?? 'unknown'
                        );
                }

                $dexPaidText = '⚪ <b>DEX Paid:</b> Unavailable';

                if ($dexPaidData !== null) {
                    if ($dexPaidData['dex_paid'] ?? false) {
                        $dexPaidTypes = ! empty($dexPaidData['types'])
                            ? ' ('.implode(', ', $dexPaidData['types']).')'
                            : '';

                        $dexPaidText =
                            "🟢 <b>DEX Paid:</b> YES{$dexPaidTypes}";
                    } else {
                        $dexPaidText =
                            '🔴 <b>DEX Paid:</b> NO';
                    }
                }

                $developerText =
                    '⚪ <b>Developer:</b> Unavailable';

                if (
                    $developerAnalysis !== null
                    && ($developerAnalysis['available'] ?? false)
                ) {
                    $creator = (string) (
                        $developerAnalysis['creator'] ?? ''
                    );

                    $creatorShort =
                        strlen($creator) > 12
                            ? substr($creator, 0, 6).
                                '...'.
                                substr($creator, -4)
                            : $creator;

                    $devSold = (bool) (
                        $developerAnalysis['dev_sold'] ?? false
                    );

                    $devSellCount = (int) (
                        $developerAnalysis['sell_count'] ?? 0
                    );

                    $devHolding =
                        $developerAnalysis['current_dev_percentage']
                        ?? null;

                    $devHoldingText =
                        $devHolding !== null
                            ? number_format(
                                (float) $devHolding,
                                2
                            ).'%'
                            : 'N/A';

                    $devSoldText =
                        $devSold
                            ? "⚠️ <b>Dev Sold:</b> YES ({$devSellCount} sells)"
                            : '🟢 <b>Dev Sold:</b> NO detected';

                    $developerText =
                        '👨‍💻 <b>Dev:</b> '.
                        "<code>{$creatorShort}</code>\n".
                        "{$devSoldText}\n".
                        "📦 <b>Dev Holding:</b> {$devHoldingText}";
                } elseif ($developerAnalysis !== null) {
                    $developerText =
                        '⚪ <b>Developer:</b> Unverified';
                }

                $pumpText =
                    '⚪ <b>Pump.fun Activity:</b> Unavailable';

                if ($pumpFeeAnalysis) {
                    $pumpStatus =
                        $pumpFeeAnalysis['status']
                        ?? 'incomplete';

                    $pumpIcon = match ($pumpStatus) {
                        'passed' => '✅',
                        'failed' => '⚠️',
                        default => '⏳',
                    };

                    $pumpText =
                        "{$pumpIcon} <b>Pump.fun Activity:</b> ".
                        strtoupper($pumpStatus)."\n".
                        '🔥 <b>Pump Fees:</b> '.
                        number_format(
                            (float) (
                                $pumpFeeAnalysis['total_pump_fees_sol']
                                ?? 0
                            ),
                            4
                        ).
                        " SOL\n".
                        '🔄 <b>On-chain Volume:</b> '.
                        number_format(
                            (float) (
                                $pumpFeeAnalysis['total_volume_sol']
                                ?? 0
                            ),
                            2
                        ).
                        " SOL\n".
                        '🧾 <b>Pump Trades:</b> '.
                        number_format(
                            (int) (
                                $pumpFeeAnalysis['trades_processed']
                                ?? 0
                            )
                        );
                }

                $paperEntryText =
                    '⚪ <b>Paper Entry:</b> Disabled';

                if ($paperEntry !== null) {
                    $paperStatus =
                        $paperEntry['status'] ?? 'unknown';

                    $paperEntryMc =
                        $paperEntry['entry_market_cap']
                        ?? null;

                    $paperMove =
                        $paperEntry[
                            'move_since_discovery_percent'
                        ]
                        ?? null;

                    $paperEntryText =
                        ($paperStatus === 'simulated_buy'
                            ? '🧪 <b>Paper Entry:</b> SIMULATED BUY'
                            : '⏭ <b>Paper Entry:</b> '.
                                strtoupper($paperStatus)
                        );

                    if ($paperEntryMc !== null) {
                        $paperEntryText .=
                            "\n🎯 <b>Paper Entry MC:</b> $".
                            number_format(
                                (float) $paperEntryMc,
                                2
                            );
                    }

                    if ($paperMove !== null) {
                        $paperEntryText .=
                            "\n🏃 <b>Move at Entry:</b> ".
                            sprintf(
                                '%+.2f%%',
                                (float) $paperMove
                            );
                    }
                }

                $message =
                    "{$alertHeading}\n\n".

                    "<b>{$symbol}</b> — {$name}\n\n".

                    '📊 <b>Momentum Score:</b> '.
                    "{$momentumScore}/100\n".

                    '💰 <b>Market Cap:</b> $'.
                    number_format($freshMarketCap, 2).
                    "\n".

                    '💧 <b>Liquidity:</b> $'.
                    number_format($freshLiquidity, 2).
                    "\n".

                    '👥 <b>Holders:</b> '.
                    number_format($freshHolders).
                    "\n".

                    "🟢 <b>Buys 1m:</b> {$freshBuys}\n".
                    "🔴 <b>Sells 1m:</b> {$freshSells}\n".

                    '👛 <b>Wallets 5m:</b> '.
                    number_format($freshWallets).
                    "\n".

                    '📈 <b>Price Change 5m:</b> '.
                    number_format($freshChange, 2).
                    "%\n\n".

                    "{$paperEntryText}\n\n".

                    "{$securityText}\n\n".

                    "{$developerText}\n\n".

                    "{$pumpText}\n\n".

                    "{$dexPaidText}\n".
                    "🏦 <b>DEX:</b> {$dexText}\n\n".

                    "📍 <b>Token Address</b>\n".
                    "<code>{$address}</code>\n\n".

                    '⚠️ Momentum signal only — not financial advice.';

                try {
                    $telegram->send($message);

                    $this->info(
                        "TELEGRAM SENT: {$symbol} | score {$momentumScore}"
                    );

                } catch (\Throwable $e) {
                    /*
                    * Telegram failure must NOT undo the saved token.
                    */
                    $this->warn(
                        "TELEGRAM FAILED: {$symbol} | ".
                        $e->getMessage()
                    );
                }
            }

            $this->newLine();
        }

        $this->info('Momentum scan finished.');

        return self::SUCCESS;
    }

    /**
     * Rank Dex discovery survivors without spending Birdeye CU.
     *
     * This is a prioritisation score only. It is not the final momentum
     * score and it is not a safety or quality guarantee.
     */
    private function calculateDexDiscoveryRank(array $item): int
    {
        $score = 0;

        $marketCap = max(1, (float) ($item['market_cap'] ?? 0));
        $liquidity = (float) ($item['liquidity'] ?? 0);
        $volume5m = (float) ($item['volume_5m_usd'] ?? 0);
        $trades5m = (int) ($item['trade_5m_count'] ?? 0);
        $buys5m = (int) ($item['buy_5m_count'] ?? 0);
        $sells5m = (int) ($item['sell_5m_count'] ?? 0);
        $priceChange5m = $item['price_change_5m'] ?? null;

        /* 1. Five-minute volume relative to market cap — max 35. */
        $volumeRatio = $volume5m / $marketCap;

        if ($volumeRatio >= 0.50) {
            $score += 35;
        } elseif ($volumeRatio >= 0.25) {
            $score += 30;
        } elseif ($volumeRatio >= 0.15) {
            $score += 24;
        } elseif ($volumeRatio >= 0.10) {
            $score += 18;
        } elseif ($volumeRatio >= 0.05) {
            $score += 10;
        } else {
            $score += 5;
        }

        /* 2. Recent transaction activity — max 25. */
        if ($trades5m >= 120) {
            $score += 25;
        } elseif ($trades5m >= 80) {
            $score += 22;
        } elseif ($trades5m >= 50) {
            $score += 18;
        } elseif ($trades5m >= 30) {
            $score += 14;
        } elseif ($trades5m >= 20) {
            $score += 10;
        } else {
            $score += 5;
        }

        /* 3. Buy pressure — max 20. */
        $totalTrades = $buys5m + $sells5m;

        if ($totalTrades > 0) {
            $buyRatio = $buys5m / $totalTrades;

            if ($buyRatio >= 0.70) {
                $score += 20;
            } elseif ($buyRatio >= 0.60) {
                $score += 16;
            } elseif ($buyRatio >= 0.55) {
                $score += 12;
            } elseif ($buyRatio >= 0.50) {
                $score += 8;
            } elseif ($buyRatio >= 0.40) {
                $score += 3;
            }
        }

        /* 4. Controlled price movement — max 15. */
        if ($priceChange5m !== null) {
            $change = (float) $priceChange5m;

            if ($change >= 2 && $change <= 20) {
                $score += 15;
            } elseif ($change > 20 && $change <= 40) {
                $score += 10;
            } elseif ($change > 0 && $change < 2) {
                $score += 7;
            } elseif ($change > 40 && $change <= 80) {
                $score += 4;
            } elseif ($change <= -20) {
                $score -= 10;
            } elseif ($change < 0) {
                $score -= 4;
            }
        }

        /* 5. Explicit pool liquidity, when Dex exposes it — max 5. */
        if ($liquidity >= 10000) {
            $score += 5;
        } elseif ($liquidity >= 5000) {
            $score += 4;
        } elseif ($liquidity >= 1000) {
            $score += 2;
        }

        return max(0, min($score, 100));
    }

    private function calculateMomentumScore(
        array $item,
        array $token
    ): int {
        $score = 0;

        $marketCap = (float) ($token['marketCap'] ?? 0);
        $liquidity = (float) ($token['liquidity'] ?? 0);
        $holders = (int) ($token['holder'] ?? 0);

        $buys = (int) ($token['buy1m'] ?? 0);
        $sells = (int) ($token['sell1m'] ?? 0);

        $wallets = (int) ($token['uniqueWallet5m'] ?? 0);

        $priceChange = (float) (
            $token['priceChange5mPercent'] ?? 0
        );

        $volume5m = (float) ($item['volume_5m_usd'] ?? 0);

        /*
        * 1. Liquidity quality — max 15
        */
        if ($marketCap > 0) {
            $liquidityRatio = $liquidity / $marketCap;

            if ($liquidityRatio >= 0.50) {
                $score += 15;
            } elseif ($liquidityRatio >= 0.25) {
                $score += 10;
            } elseif ($liquidityRatio >= 0.10) {
                $score += 5;
            }
        }

        /*
        * 2. Holder participation — max 15
        */
        if ($holders >= 500) {
            $score += 15;
        } elseif ($holders >= 200) {
            $score += 12;
        } elseif ($holders >= 50) {
            $score += 8;
        } elseif ($holders >= 20) {
            $score += 5;
        }

        /*
        * 3. Recent buy pressure — max 25
        */
        $totalTrades = $buys + $sells;

        if ($totalTrades > 0) {
            $buyRatio = $buys / $totalTrades;

            if ($buyRatio >= 0.70) {
                $score += 25;
            } elseif ($buyRatio >= 0.60) {
                $score += 18;
            } elseif ($buyRatio >= 0.55) {
                $score += 10;
            } elseif ($buyRatio >= 0.50) {
                $score += 5;
            }
        }

        /*
        * 4. Wallet participation — max 15
        */
        if ($wallets >= 200) {
            $score += 15;
        } elseif ($wallets >= 100) {
            $score += 12;
        } elseif ($wallets >= 50) {
            $score += 8;
        } elseif ($wallets >= 25) {
            $score += 5;
        }

        /*
        * 5. Price momentum — max 20
        *
        * We prefer controlled upward movement.
        */
        if ($priceChange >= 2 && $priceChange <= 20) {
            $score += 20;

        } elseif ($priceChange > 20 && $priceChange <= 40) {
            $score += 10;

        } elseif ($priceChange > 0 && $priceChange < 2) {
            $score += 5;

        } elseif ($priceChange > 100) {
            // Very overheated.
            $score -= 15;

        } elseif ($priceChange > 60) {
            $score -= 10;

        } elseif ($priceChange <= -40) {
            $score -= 30;

        } elseif ($priceChange <= -20) {
            $score -= 20;

        } elseif ($priceChange <= -5) {
            $score -= 10;
        }

        /*
        * 6. 5-minute volume relative to MC — max 10
        */
        if ($marketCap > 0) {
            $volumeRatio = $volume5m / $marketCap;

            if ($volumeRatio >= 2) {
                $score += 10;
            } elseif ($volumeRatio >= 1) {
                $score += 8;
            } elseif ($volumeRatio >= 0.50) {
                $score += 5;
            } elseif ($volumeRatio >= 0.25) {
                $score += 3;
            }
        }

        return max(0, min($score, 100));
    }
}
