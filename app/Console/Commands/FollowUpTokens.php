<?php

namespace App\Console\Commands;

use App\Models\TokenScan;
use App\Services\BirdeyeService;
use App\Services\DexScreenerService;
use Illuminate\Console\Command;
use App\Models\TokenScanHistory;
use App\Services\TelegramService;

class FollowUpTokens extends Command
{
    protected $signature = 'tokens:follow-up';

    protected $description = 'Recheck recently discovered Solana token candidates';

    public function handle(
        BirdeyeService $birdeye,
        DexScreenerService $dexscreener,
        TelegramService $telegram
    ): int {
        $this->info('Checking recent token candidates...');

        /*
         * For now:
         *
         * - Only tokens discovered in the last hour
         * - At least 5 minutes since their previous check
         * - Previous score of at least 25
         *
         * We deliberately include 25–39 because some tokens
         * may strengthen after their initial discovery.
         */
        $tokens = TokenScan::query()
            ->where('first_seen_at', '>=', now()->subHour())
            ->where(function ($query) {
                $query->whereNull('last_scanned_at')
                    ->orWhere(
                        'last_scanned_at',
                        '<=',
                        now()->subMinutes(5)
                    );
            })
            ->where('score', '>=', 25)
            ->orderByDesc('score')
            ->limit(20)
            ->get();

        if ($tokens->isEmpty()) {
            $this->warn('No tokens currently need a follow-up check.');

            return self::SUCCESS;
        }

        $this->info(
            'Found ' . $tokens->count() . ' token(s) to recheck.'
        );

        foreach ($tokens as $scan) {
            $symbol = $scan->symbol ?? 'UNKNOWN';
            $address = $scan->address;

            $oldScore = (int) $scan->score;
            $oldMarketCap = (float) $scan->market_cap;
            $oldHolders = (int) $scan->holders;

            try {
                /*
                 * Stay comfortably inside Birdeye rate limits.
                 */
                usleep(1200000);

                $response = $birdeye->tokenOverview($address);
                $token = $response['data'] ?? null;

                if (!$token) {
                    $this->warn(
                        "FOLLOW-UP: {$symbol} | Birdeye data unavailable"
                    );

                    $scan->update([
                        'last_scanned_at' => now(),
                    ]);

                    continue;
                }

                $marketCap = (float) ($token['marketCap'] ?? 0);
                $liquidity = (float) ($token['liquidity'] ?? 0);
                $holders = (int) ($token['holder'] ?? 0);

                $score = $this->calculateScore($token);

                /*
                 * DexScreener is still confirmation/enrichment only.
                 */
                $dexData = null;

                try {
                    $dexData = $dexscreener->analyzeToken($address);
                } catch (\Throwable $e) {
                    $this->warn(
                        "DEXSCREENER UNAVAILABLE: {$symbol} | " .
                        $e->getMessage()
                    );
                }

                $scoreChange = $score - $oldScore;
                $holderChange = $holders - $oldHolders;

                $marketCapChange = $oldMarketCap > 0
                    ? (($marketCap - $oldMarketCap) / $oldMarketCap) * 100
                    : 0;

                    $previousStatus = $scan->follow_up_status;

                    /*
                    * Deterioration takes priority.
                    */
                    $deteriorating =
                        $score <= 20 ||
                        $marketCapChange <= -30 ||
                        $liquidity < 500 ||
                        $holders < 5;

                    /*
                    * Strengthening requires several positive signals,
                    * rather than score alone.
                    */
                    $strengthening =
                        !$deteriorating &&
                        $score >= 40 &&
                        $marketCapChange >= 20 &&
                        $holderChange >= 10 &&
                        $liquidity >= 500;

                    if ($deteriorating) {
                        $followUpStatus = 'deteriorating';

                    } elseif ($strengthening) {
                        $followUpStatus = 'strengthening';

                    } else {
                        $followUpStatus = 'neutral';
                    }

                $this->newLine();

                $this->info(
                    sprintf(
                        'FOLLOW-UP: %s | Score: %d → %d (%+d)',
                        $symbol,
                        $oldScore,
                        $score,
                        $scoreChange
                    )
                );

                $this->line(
                    sprintf(
                        'MC: $%s → $%s (%+.2f%%)',
                        number_format($oldMarketCap, 2),
                        number_format($marketCap, 2),
                        $marketCapChange
                    )
                );

                $this->line(
                    sprintf(
                        'Holders: %d → %d (%+d)',
                        $oldHolders,
                        $holders,
                        $holderChange
                    )
                );

                $this->line(
                    sprintf(
                        'Liquidity: $%s | Buys 1m: %d | Sells 1m: %d | Wallets 5m: %d | Change 5m: %.2f%%',
                        number_format($liquidity, 2),
                        (int) ($token['buy1m'] ?? 0),
                        (int) ($token['sell1m'] ?? 0),
                        (int) ($token['uniqueWallet5m'] ?? 0),
                        (float) ($token['priceChange5mPercent'] ?? 0)
                    )
                );

                if ($dexData && ($dexData['available'] ?? false)) {
                    $this->line(
                        sprintf(
                            'DEXSCREENER: MC: $%s | Liquidity: %s | Age: %s min | Dex: %s',
                            number_format(
                                (float) ($dexData['market_cap'] ?? 0),
                                2
                            ),
                            $dexData['liquidity_usd'] !== null
                                ? '$' . number_format(
                                    (float) $dexData['liquidity_usd'],
                                    2
                                )
                                : 'N/A',
                            $dexData['pair_age_minutes'] ?? 'N/A',
                            $dexData['dex'] ?? 'N/A'
                        )
                    );
                } else {
                    $this->warn(
                        "DEXSCREENER: {$symbol} | No pair data available"
                    );
                }

                TokenScanHistory::create([
                    'token_scan_id' => $scan->id,

                    'address' => $address,
                    'symbol' => $token['symbol'] ?? $scan->symbol,
                    'name' => $token['name'] ?? $scan->name,

                    'snapshot_type' => 'follow_up',

                    'price' => $token['price'] ?? null,
                    'market_cap' => $marketCap,
                    'liquidity' => $liquidity,
                    'holders' => $holders,

                    'volume_1m' => $token['v1m'] ?? null,
                    'buys_1m' => $token['buy1m'] ?? null,
                    'sells_1m' => $token['sell1m'] ?? null,

                    'unique_wallets_5m' =>
                        $token['uniqueWallet5m'] ?? null,

                    'price_change_5m' =>
                        $token['priceChange5mPercent'] ?? null,

                    'score' => $score,

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

                    'raw_data' => $token,

                    'scanned_at' => now(),
                ]);

                if (
                    $followUpStatus !== 'neutral' &&
                    $followUpStatus !== $previousStatus
                ) {
                    if ($followUpStatus === 'strengthening') {

                        $dexText = '';

                        if ($dexData && ($dexData['available'] ?? false)) {
                            $dexText =
                                "\nDex: <b>" .
                                ($dexData['dex'] ?? 'Unknown') .
                                "</b>\nDex MC: $" .
                                number_format(
                                    (float) ($dexData['market_cap'] ?? 0),
                                    2
                                );
                        }

                        $message =
                            "🚀 <b>TOKEN STRENGTHENING</b>\n\n" .
                            "<b>{$symbol}</b> — {$scan->name}\n\n" .

                            "Score: <b>{$oldScore} → {$score}</b>\n" .

                            "Market Cap: $" .
                            number_format($oldMarketCap, 2) .
                            " → $" .
                            number_format($marketCap, 2) .
                            " (" .
                            sprintf('%+.2f', $marketCapChange) .
                            "%)\n" .

                            "Holders: {$oldHolders} → {$holders} " .
                            "(" . sprintf('%+d', $holderChange) . ")\n" .

                            "Liquidity: $" .
                            number_format($liquidity, 2) . "\n" .

                            "Buys 1m: " .
                            (int) ($token['buy1m'] ?? 0) . "\n" .

                            "Sells 1m: " .
                            (int) ($token['sell1m'] ?? 0) . "\n" .

                            "Wallets 5m: " .
                            (int) ($token['uniqueWallet5m'] ?? 0) .

                            $dexText .

                            "\n\n<code>{$address}</code>\n\n" .
                            "⚠️ Follow-up scanner alert only — not a buy recommendation.";

                    } else {

                        $message =
                            "🔻 <b>TOKEN DETERIORATING</b>\n\n" .
                            "<b>{$symbol}</b> — {$scan->name}\n\n" .

                            "Score: <b>{$oldScore} → {$score}</b>\n" .

                            "Market Cap: $" .
                            number_format($oldMarketCap, 2) .
                            " → $" .
                            number_format($marketCap, 2) .
                            " (" .
                            sprintf('%+.2f', $marketCapChange) .
                            "%)\n" .

                            "Holders: {$oldHolders} → {$holders} " .
                            "(" . sprintf('%+d', $holderChange) . ")\n" .

                            "Liquidity: $" .
                            number_format($liquidity, 2) . "\n" .

                            "Price change 5m: " .
                            number_format(
                                (float) ($token['priceChange5mPercent'] ?? 0),
                                2
                            ) .
                            "%\n\n" .

                            "<code>{$address}</code>\n\n" .
                            "⚠️ Follow-up scanner alert only — not a sell recommendation.";
                    }

                    try {
                        $telegram->send($message);

                        $this->info(
                            "Telegram follow-up alert sent for {$symbol}: {$followUpStatus}"
                        );

                        $scan->last_follow_up_alerted_at = now();

                    } catch (\Throwable $e) {
                        $this->error(
                            "Telegram follow-up failed for {$symbol}: " .
                            $e->getMessage()
                        );
                    }
                }

                /*
                 * Store the latest snapshot.
                 */
                $scan->update([
                    'symbol' => $token['symbol'] ?? $scan->symbol,
                    'name' => $token['name'] ?? $scan->name,

                    'price' => $token['price'] ?? $scan->price,
                    'market_cap' => $token['marketCap'] ?? null,
                    'liquidity' => $token['liquidity'] ?? null,
                    'holders' => $token['holder'] ?? null,

                    'volume_1m' => $token['v1m'] ?? null,
                    'buys_1m' => $token['buy1m'] ?? null,
                    'sells_1m' => $token['sell1m'] ?? null,

                    'unique_wallets_5m' =>
                        $token['uniqueWallet5m'] ?? null,

                    'price_change_5m' =>
                        $token['priceChange5mPercent'] ?? null,

                    'score' => $score,

                    'follow_up_status' => $followUpStatus,

                    'last_follow_up_alerted_at' =>
                        $scan->last_follow_up_alerted_at,
                    'raw_data' => $token,
                    'last_scanned_at' => now(),
                ]);

            } catch (\Throwable $e) {
                $this->error(
                    "FOLLOW-UP FAILED: {$symbol} | " . $e->getMessage()
                );
            }
        }

        $this->newLine();
        $this->info('Follow-up scan finished.');

        return self::SUCCESS;
    }

    private function calculateScore(array $token): int
    {
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

        if ($marketCap > 0) {
            $liquidityRatio = $liquidity / $marketCap;

            if ($liquidityRatio >= 0.50) {
                $score += 20;
            } elseif ($liquidityRatio >= 0.25) {
                $score += 10;
            }
        }

        if ($holders >= 50) {
            $score += 20;
        } elseif ($holders >= 20) {
            $score += 15;
        } elseif ($holders >= 10) {
            $score += 10;
        }

        if ($buys > 0) {
            $totalTrades = $buys + $sells;

            if ($totalTrades > 0) {
                $buyRatio = $buys / $totalTrades;

                if ($buyRatio >= 0.70) {
                    $score += 25;
                } elseif ($buyRatio >= 0.60) {
                    $score += 15;
                } elseif ($buyRatio >= 0.50) {
                    $score += 5;
                }
            }
        }

        if ($wallets >= 30) {
            $score += 20;
        } elseif ($wallets >= 15) {
            $score += 15;
        } elseif ($wallets >= 10) {
            $score += 10;
        }

        if ($priceChange >= 2 && $priceChange <= 20) {
            $score += 15;

        } elseif ($priceChange > 0 && $priceChange < 2) {
            $score += 5;

        } elseif ($priceChange > 50) {
            $score -= 10;

        } elseif ($priceChange <= -50) {
            $score -= 25;

        } elseif ($priceChange <= -20) {
            $score -= 15;

        } elseif ($priceChange <= -5) {
            $score -= 10;
        }

        return max(0, min($score, 100));
    }
}