<?php

namespace App\Console\Commands;

use App\Models\TokenScan;
use App\Services\BirdeyeService;
use App\Services\DexScreenerService;
use App\Services\GoPlusService;
use App\Services\TelegramService;
use Illuminate\Console\Command;

class ScanMomentumTokens extends Command
{
    protected $signature = 'tokens:momentum';

    protected $description = 'Scan Solana tokens showing early momentum';

    public function handle(
        BirdeyeService $birdeye,
        GoPlusService $goplus,
        DexScreenerService $dexscreener,
        TelegramService $telegram
    ): int {
        $this->info('Fetching momentum candidates...');

        try {
            $response = $birdeye->momentumTokens(20);
        } catch (\Throwable $e) {
            $this->error(
                'Momentum list failed: ' . $e->getMessage()
            );

            return self::FAILURE;
        }

        $items = $response['data']['items'] ?? [];

        if (empty($items)) {
            $this->warn('No momentum candidates returned.');

            return self::SUCCESS;
        }

        $this->info(
            'Found ' . count($items) . ' momentum candidates.'
        );

        foreach ($items as $item) {
            $address = $item['address'] ?? null;
            $symbol = $item['symbol'] ?? 'UNKNOWN';
            $name = $item['name'] ?? 'Unknown';

            if (!$address) {
                continue;
            }

            $marketCap = (float) ($item['market_cap'] ?? 0);
            $liquidity = (float) ($item['liquidity'] ?? 0);
            $volume5m = (float) ($item['volume_5m_usd'] ?? 0);
            $trades5m = (int) ($item['trade_5m_count'] ?? 0);

            /*
             * V3 discovery-stage filters.
             */
            if (
                $marketCap < 5000 ||
                $marketCap > 100000 ||
                $liquidity < 1000 ||
                $volume5m < 500 ||
                $trades5m < 10
            ) {
                continue;
            }

            /*
             * Don't send a fresh momentum-discovery alert for
             * something that is already actively tracked.
             */
            $existing = TokenScan::where('address', $address)->first();

            if (
                $existing &&
                ($existing->lifecycle_status ?? 'active') === 'active'
            ) {
                $this->line(
                    "Already tracking: {$symbol}"
                );

                continue;
            }

            $this->info(
                sprintf(
                    'V3 CANDIDATE: %s | MC: $%s | Liquidity: $%s | Volume 5m: $%s | Trades 5m: %d',
                    $symbol,
                    number_format($marketCap, 2),
                    number_format($liquidity, 2),
                    number_format($volume5m, 2),
                    $trades5m
                )
            );

            /*
             * Now spend the extra Birdeye request only on
             * shortlisted candidates.
             */
            try {
                usleep(1200000);

                $overviewResponse =
                    $birdeye->tokenOverview($address);

                $token =
                    $overviewResponse['data'] ?? null;

                if (!$token) {
                    $this->warn(
                        "No overview data for {$symbol}"
                    );

                    continue;
                }

            } catch (\Throwable $e) {
                $this->warn(
                    "Overview unavailable: {$symbol} | " .
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
                    "MOMENTUM REJECT: {$symbol} | liquidity $" .
                    number_format($overviewLiquidity, 2)
                );

                continue;
            }

            if (
                $overviewMarketCap < 5000 ||
                $overviewMarketCap > 100000
            ) {
                $this->warn(
                    "MOMENTUM REJECT: {$symbol} | MC $" .
                    number_format($overviewMarketCap, 2)
                );

                continue;
            }

            /*
            * Don't chase something already collapsing.
            */
            if ($priceChange <= -40) {
                $this->warn(
                    "MOMENTUM REJECT: {$symbol} | collapsing " .
                    number_format($priceChange, 2) . '%'
                );

                continue;
            }

            /*
            * Don't chase an almost vertical candle either.
            */
            if ($priceChange > 150) {
                $this->warn(
                    "MOMENTUM REJECT: {$symbol} | overheated +" .
                    number_format($priceChange, 2) . '%'
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
                        'GoPlus unavailable: ' . $e->getMessage()
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
                            'No GoPlus security data returned'
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

            if (!$securityUnavailable) {
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

                if (!($initialDexData['available'] ?? false)) {
                    $this->warn("DEX UNAVAILABLE: {$symbol} | no pair data");
                    continue;
                }

                $initialDexMarketCap = $initialDexData['market_cap'] ?? null;
                $initialDexLiquidity = $initialDexData['liquidity_usd'] ?? null;
                $dexName = $initialDexData['dex'] ?? 'unknown';
                $pairAge = $initialDexData['pair_age_minutes'] ?? null;

                $this->info(sprintf(
                    'DEX SNAPSHOT: %s | DEX: %s | MC: %s | Liquidity: %s | Pair Age: %s min',
                    $symbol,
                    $dexName,
                    $initialDexMarketCap !== null ? '$' . number_format($initialDexMarketCap, 2) : 'N/A',
                    $initialDexLiquidity !== null ? '$' . number_format($initialDexLiquidity, 2) : 'N/A',
                    $pairAge !== null ? number_format($pairAge, 0) : 'N/A'
                ));
            } catch (\Throwable $e) {
                $this->warn("DEX UNAVAILABLE: {$symbol} | " . $e->getMessage());
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

                if (!($dexData['available'] ?? false)) {
                    $this->warn("DEX CONFIRMATION FAILED: {$symbol} | pair disappeared/unavailable");
                    continue;
                }
            } catch (\Throwable $e) {
                $this->warn("DEX CONFIRMATION FAILED: {$symbol} | " . $e->getMessage());
                continue;
            }

            $freshDexMarketCap = (float) ($dexData['market_cap'] ?? 0);
            $freshDexLiquidityRaw = $dexData['liquidity_usd'] ?? null;
            $freshDexLiquidity = $freshDexLiquidityRaw !== null
                ? (float) $freshDexLiquidityRaw
                : null;

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
                $freshDexMarketCap > 0 ? '$' . number_format($freshDexMarketCap, 2) : 'N/A',
                $dexMarketCapChange !== null ? number_format($dexMarketCapChange, 2) . '%' : 'N/A'
            ));

            $finalLevel = match (true) {
                $momentumScore >= 80 => 'strong',
                $momentumScore >= 65 => 'candidate',
                default => 'watchlist',
            };

            $scan = TokenScan::updateOrCreate(
                [
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

                    'unique_wallets_5m' =>
                        $token['uniqueWallet5m'] ?? 0,

                    'price_change_5m' =>
                        $token['priceChange5mPercent'] ?? 0,

                    'score' => $momentumScore,

                    'security_score' =>
                        $securityUnavailable
                            ? null
                            : ($security['score'] ?? null),

                    'security_passed' =>
                        $securityUnavailable
                            ? false
                            : ($security['passed'] ?? false),

                    'security_risks' =>
                        $security['risks'] ?? [],

                    'raw_data' => [
                        'birdeye_v3' => $item,
                        'birdeye_overview' => $token,
                        'initial_dexscreener' => $initialDexData,
                        'dexscreener' => $dexData,
                        'momentum_level' => $finalLevel,
                        'momentum_score' => $momentumScore,
                        'confirmation_source' => 'dexscreener',
                        'dex_market_cap_change_8s' => $dexMarketCapChange,
                        'security_unavailable' => $securityUnavailable,
                    ],

                    'first_seen_at' =>
                        $existing?->first_seen_at ?? now(),

                    'last_scanned_at' => now(),
                ]
            );

            \App\Models\TokenScanHistory::create([
                'token_scan_id' => $scan->id,

                'address' => $address,
                'symbol' => $symbol,
                'name' => $name,

                'snapshot_type' =>
                    'momentum_discovery',

                'price' =>
                    $token['price'] ?? null,

                'market_cap' =>
                    $token['marketCap'] ?? null,

                'liquidity' =>
                    $token['liquidity'] ?? null,

                'holders' =>
                    $token['holder'] ?? 0,

                'volume_1m' =>
                    $token['v1m'] ?? 0,

                'buys_1m' =>
                    $token['buy1m'] ?? 0,

                'sells_1m' =>
                    $token['sell1m'] ?? 0,

                'unique_wallets_5m' =>
                    $token['uniqueWallet5m'] ?? 0,

                'price_change_5m' =>
                    $token['priceChange5mPercent'] ?? 0,

                'score' =>
                    $momentumScore,

                'dex_available' =>
                    (bool) ($dexData['available'] ?? false),

                'dex' =>
                    $dexData['dex'] ?? null,

                'dex_pair_address' =>
                    $dexData['pair_address'] ?? null,

                'dex_market_cap' =>
                    $dexData['market_cap'] ?? null,

                'dex_liquidity' =>
                    $dexData['liquidity_usd'] ?? null,

                'dex_pair_age_minutes' =>
                    $dexData['pair_age_minutes'] ?? null,

                'raw_data' => [
                    'birdeye_v3' => $item,
                    'birdeye_overview' => $token,
                    'initial_dexscreener' => $initialDexData,
                    'dexscreener' => $dexData,
                    'security' => $security,
                    'momentum_score' => $momentumScore,
                    'confirmation_source' => 'dexscreener',
                    'dex_market_cap_change_8s' => $dexMarketCapChange,
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
                    $momentumScore >= 80 =>
                        '🔥 <b>STRONG MOMENTUM DETECTED</b>',

                    default =>
                        '🟢 <b>MOMENTUM CANDIDATE</b>',
                };

                if ($securityUnavailable) {
                    $securityText =
                        "⚠️ <b>SECURITY: UNVERIFIED</b>\n" .
                        "GoPlus security data was unavailable.";
                } else {
                    $securityText =
                        "✅ <b>Security:</b> GoPlus passed" .
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

                $message =
                    "{$alertHeading}\n\n" .

                    "<b>{$symbol}</b> — {$name}\n\n" .

                    "📊 <b>Momentum Score:</b> " .
                    "{$momentumScore}/100\n" .

                    "💰 <b>Market Cap:</b> $" .
                    number_format($freshMarketCap, 2) .
                    "\n" .

                    "💧 <b>Liquidity:</b> $" .
                    number_format($freshLiquidity, 2) .
                    "\n" .

                    "👥 <b>Holders:</b> " .
                    number_format($freshHolders) .
                    "\n" .

                    "🟢 <b>Buys 1m:</b> {$freshBuys}\n" .
                    "🔴 <b>Sells 1m:</b> {$freshSells}\n" .

                    "👛 <b>Wallets 5m:</b> " .
                    number_format($freshWallets) .
                    "\n" .

                    "📈 <b>Price Change 5m:</b> " .
                    number_format($freshChange, 2) .
                    "%\n\n" .

                    "{$securityText}\n\n" .

                    "🏦 <b>DEX:</b> {$dexText}\n\n" .

                    "📍 <b>Token Address</b>\n" .
                    "<code>{$address}</code>\n\n" .

                    "⚠️ Momentum signal only — not financial advice.";

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
                        "TELEGRAM FAILED: {$symbol} | " .
                        $e->getMessage()
                    );
                }
            }

            $this->newLine();
        }

        
        $this->info('Momentum scan finished.');

        return self::SUCCESS;
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