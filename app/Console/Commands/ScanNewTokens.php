<?php

namespace App\Console\Commands;

use App\Chain;
use App\Models\TokenScan;
use App\Models\TokenScanHistory;
use App\Services\ApplicationSettingsService;
use App\Services\BirdeyeService;
use App\Services\DexScreenerService;
use App\Services\EthereumScannerService;
use App\Services\GoPlusService;
use App\Services\NewTokenClassificationService;
use App\Services\PaperTradeEntryService;
use App\Services\TelegramService;
use App\Services\TradeOpportunityService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ScanNewTokens extends Command
{
    protected $signature = 'tokens:scan {--chain=solana : Blockchain to scan (solana or ethereum)}';

    protected $description = 'Scan newly listed tokens on a supported blockchain';

    public function handle(BirdeyeService $birdeye, GoPlusService $goplus, TelegramService $telegram, DexScreenerService $dexscreener, TradeOpportunityService $opportunities, EthereumScannerService $ethereumScanner, NewTokenClassificationService $classifications, ApplicationSettingsService $settings, PaperTradeEntryService $entries): int
    {
        try {
            $chain = Chain::fromInput($this->option('chain'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($chain === Chain::Ethereum) {
            $this->warn('Ethereum security status: Solana-specific Birdeye, GoPlus, holder, developer-sale, and Pump.fun checks are unavailable and are not reported as passed.');
            $result = $ethereumScanner->scan('new-token');
            $this->info(sprintf('Ethereum new-token scan finished: %d profiles, %d qualified, %d paper buys.', $result['profiles'], $result['qualified'], $result['positions']));

            return self::SUCCESS;
        }

        $this->info('Fetching new Solana listings...');

        $watchlistThreshold = 40;
        $alertThreshold = 50;

        // $response = $birdeye->newListings();

        try {
            $response = $birdeye->newListings();
        } catch (Throwable $e) {
            $this->error(
                'Could not fetch Birdeye listings: '.$e->getMessage()
            );

            return self::FAILURE;
        }

        $items = $response['data']['items'] ?? [];

        if (empty($items)) {
            $this->warn('No new listings returned.');

            return self::SUCCESS;
        }

        $this->info('Found '.count($items).' listings.');

        $seenAddresses = [];

        foreach ($items as $listing) {
            $address = $listing['address'] ?? null;

            if (! $address || isset($seenAddresses[$address])) {
                continue;
            }

            $seenAddresses[$address] = true;

            $symbol = $listing['symbol'] ?? 'UNKNOWN';
            $name = $listing['name'] ?? 'Unknown';

            // Already scanned
            if (TokenScan::where('chain', $chain->value)->where('address', $address)->exists()) {
                $this->line("Skipping existing token: {$address}");

                continue;
            }

            $liquidity = (float) ($listing['liquidity'] ?? 0);

            // Cheap discovery-stage filter
            if ($liquidity < 100) {
                $this->line(
                    'Skipping low liquidity: '.
                    ($listing['symbol'] ?? $address).
                    " ({$liquidity})"
                );

                continue;
            }

            try {
                // Keep comfortably below our Birdeye account rate limit.
                $this->pause(1200000); // 1.2 seconds

                $overviewResponse = $birdeye->tokenOverview($address);

                $token = $overviewResponse['data'] ?? null;

                $symbol = $token['symbol'] ?? $symbol;
                $name = $token['name'] ?? $name;

                if (! $token) {
                    $this->warn("No overview data for {$address}");

                    continue;
                }

                $marketCap = (float) ($token['marketCap'] ?? 0);
                $discoveryMarketCap = $marketCap;
                $overviewLiquidity = (float) ($token['liquidity'] ?? 0);

                // Hard filter: market cap must actually exist.
                if ($marketCap <= 0) {
                    $this->line(
                        'Skipping missing market cap: '.
                        ($token['symbol'] ?? $listing['symbol'] ?? $address)
                    );

                    continue;
                }

                // Our early-stage market-cap window.
                if ($marketCap < 2000 || $marketCap > 20000) {
                    $this->line(
                        sprintf(
                            'Skipping MC: %s ($%s)',
                            $token['symbol'] ?? $listing['symbol'] ?? $address,
                            number_format($marketCap, 2)
                        )
                    );

                    continue;
                }

                // Require meaningful liquidity.
                if ($overviewLiquidity < 500) {
                    $this->line(
                        sprintf(
                            'Skipping liquidity: %s ($%s)',
                            $token['symbol'] ?? $listing['symbol'] ?? $address,
                            number_format($overviewLiquidity, 2)
                        )
                    );

                    continue;
                }

                // Very small holder counts are too risky/noisy for our scanner.
                $holders = (int) ($token['holder'] ?? 0);

                if ($holders < 5) {
                    $this->line(
                        sprintf(
                            'Skipping holders: %s (%d holders)',
                            $token['symbol'] ?? $address,
                            $holders
                        )
                    );

                    continue;
                }

                $this->info(
                    sprintf(
                        'PASSED BASIC FILTERS: %s | MC: $%s | Liquidity: $%s | Holders: %d',
                        $symbol,
                        number_format($marketCap, 2),
                        number_format($overviewLiquidity, 2),
                        $holders
                    )
                );

                // GoPlus security screening.
                $this->pause(2200000);

                $securityUnavailable = false;

                try {
                    $security = $goplus->evaluateToken($address);
                } catch (ConnectionException $e) {
                    $securityUnavailable = true;

                    $security = [
                        'passed' => null,
                        'score' => null,
                        'risks' => ['GoPlus connection unavailable'],
                    ];

                    $this->warn(
                        "SECURITY UNAVAILABLE: {$symbol} | continuing as unverified"
                    );

                } catch (Throwable $e) {
                    $securityUnavailable = true;

                    $security = [
                        'passed' => null,
                        'score' => null,
                        'risks' => [$e->getMessage()],
                    ];

                    $this->warn(
                        "SECURITY UNAVAILABLE: {$symbol} | continuing as unverified"
                    );
                }

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
                            'risks' => ['No GoPlus security data returned'],
                        ];

                        $this->warn(
                            "SECURITY UNAVAILABLE: {$symbol} | continuing as unverified"
                        );

                    } else {
                        $riskText = implode(', ', $risks);

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

                $score = $this->calculateScore($token);

                $this->line(
                    sprintf(
                        'INITIAL ACTIVITY SCORE: %s | Score: %d | Buys: %d | Sells: %d | Wallets 5m: %d | Change 5m: %.2f%%',
                        $symbol,
                        $score,
                        (int) ($token['buy1m'] ?? 0),
                        (int) ($token['sell1m'] ?? 0),
                        (int) ($token['uniqueWallet5m'] ?? 0),
                        (float) ($token['priceChange5mPercent'] ?? 0)
                    )
                );

                $fresh = null;
                $freshScore = null;
                $dexData = null;

                if ($score >= $watchlistThreshold) {
                    // Get a fresh snapshot immediately before alerting.
                    $this->pause(1200000);

                    $freshResponse = $birdeye->tokenOverview($address);
                    $fresh = $freshResponse['data'] ?? null;

                    if (! $fresh) {
                        $this->warn("Alert cancelled: {$symbol} fresh overview unavailable");

                        continue;
                    }

                    $freshMarketCap = (float) ($fresh['marketCap'] ?? 0);
                    $freshLiquidity = (float) ($fresh['liquidity'] ?? 0);
                    $freshHolders = (int) ($fresh['holder'] ?? 0);

                    $this->line(
                        sprintf(
                            'FRESH SNAPSHOT: %s | MC: $%s | Liquidity: $%s | Holders: %d',
                            $symbol,
                            number_format($freshMarketCap, 2),
                            number_format($freshLiquidity, 2),
                            $freshHolders
                        )
                    );

                    if ($freshMarketCap < 2000 || $freshMarketCap > 20000) {
                        $this->warn(
                            "Alert cancelled: {$symbol} MC changed to $".
                            number_format($freshMarketCap, 2)
                        );

                        continue;
                    }

                    if ($freshLiquidity < 500) {
                        $this->warn(
                            "Alert cancelled: {$symbol} liquidity changed to $".
                            number_format($freshLiquidity, 2)
                        );

                        continue;
                    }

                    if ($freshHolders < 5) {
                        $this->warn(
                            "Alert cancelled: {$symbol} holders changed to {$freshHolders}"
                        );

                        continue;
                    }

                    $freshScore = $this->calculateScore($fresh);

                    $freshBuys = (int) ($fresh['buy1m'] ?? 0);
                    $freshSells = (int) ($fresh['sell1m'] ?? 0);
                    $freshWallets = (int) ($fresh['uniqueWallet5m'] ?? 0);
                    $freshChange = (float) ($fresh['priceChange5mPercent'] ?? 0);

                    $this->line(
                        sprintf(
                            'FRESH SCORE: %s | Score: %d | Buys: %d | Sells: %d | Wallets 5m: %d | Change 5m: %.2f%%',
                            $symbol,
                            $freshScore,
                            $freshBuys,
                            $freshSells,
                            $freshWallets,
                            $freshChange
                        )
                    );

                    if ($freshScore < $watchlistThreshold) {
                        $this->warn(
                            "Alert cancelled: {$symbol} score dropped to {$freshScore}"
                        );

                        continue;
                    }

                    /*
                    * DexScreener confirmation.
                    * This is informational for now — it does NOT reject the token.
                    */

                    try {
                        $dexData = $dexscreener->analyzeToken($address);
                    } catch (Throwable $e) {
                        $this->warn(
                            "DEXSCREENER UNAVAILABLE: {$symbol} | ".$e->getMessage()
                        );
                    }

                    if ($dexData && ($dexData['available'] ?? false)) {
                        $this->line(
                            sprintf(
                                'DEXSCREENER: %s | MC: $%s | Liquidity: %s | Age: %s min | Dex: %s',
                                $symbol,
                                number_format(
                                    (float) ($dexData['market_cap'] ?? 0),
                                    2
                                ),
                                $dexData['liquidity_usd'] !== null
                                    ? '$'.number_format(
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

                    // Use the confirmed snapshot for persistence and Telegram.
                    $token = $fresh;
                    $score = $freshScore;
                    $marketCap = $freshMarketCap;
                    $overviewLiquidity = $freshLiquidity;
                    $holders = $freshHolders;
                    $symbol = $fresh['symbol'] ?? $symbol;
                    $name = $fresh['name'] ?? $name;
                }

                $classification = $classifications->classify($score, $securityUnavailable, $alertThreshold);
                $paperEntryDecision = [
                    'classification' => $classification['classification'],
                    'trade_eligible' => $classification['trade_eligible'],
                    'paper_entry_status' => 'skipped',
                    'paper_entry_reason' => match ($classification['classification']) {
                        'unverified' => 'Security is unverified.',
                        'watchlist' => 'Final classification is not Strong Candidate.',
                        default => 'Entry checks have not run.',
                    },
                ];
                $paperPosition = null;
                $paperBuyExecuted = false;

                $scan = TokenScan::create([
                    'chain' => $chain->value,
                    'address' => $address,
                    'symbol' => $token['symbol'] ?? $listing['symbol'] ?? null,
                    'name' => $token['name'] ?? $listing['name'] ?? null,

                    'price' => $token['price'] ?? null,
                    'market_cap' => $token['marketCap'] ?? null,
                    'liquidity' => $token['liquidity'] ?? $liquidity,

                    'holders' => $token['holder'] ?? null,

                    'volume_1m' => $token['v1m'] ?? null,
                    'buys_1m' => $token['buy1m'] ?? null,
                    'sells_1m' => $token['sell1m'] ?? null,

                    'unique_wallets_5m' => $token['uniqueWallet5m'] ?? null,

                    'price_change_5m' => $token['priceChange5mPercent'] ?? null,

                    'score' => $score,

                    'security_score' => $security['score'],
                    'security_passed' => $securityUnavailable
                        ? false
                        : $security['passed'],
                    'security_risks' => $security['risks'],

                    'raw_data' => array_merge($token, ['scanner_decision' => $paperEntryDecision]),

                    'first_seen_at' => now(),
                    'last_scanned_at' => now(),
                ]);

                TokenScanHistory::create([
                    'token_scan_id' => $scan->id,

                    'address' => $address,
                    'symbol' => $token['symbol'] ?? $listing['symbol'] ?? null,
                    'name' => $token['name'] ?? $listing['name'] ?? null,

                    'snapshot_type' => 'discovery',

                    'price' => $token['price'] ?? null,
                    'market_cap' => $token['marketCap'] ?? null,
                    'liquidity' => $token['liquidity'] ?? null,
                    'holders' => $token['holder'] ?? null,

                    'volume_1m' => $token['v1m'] ?? null,
                    'buys_1m' => $token['buy1m'] ?? null,
                    'sells_1m' => $token['sell1m'] ?? null,

                    'unique_wallets_5m' => $token['uniqueWallet5m'] ?? null,

                    'price_change_5m' => $token['priceChange5mPercent'] ?? null,

                    'score' => $score,

                    'dex_available' => (bool) ($dexData['available'] ?? false),

                    'dex' => $dexData['dex'] ?? null,

                    'dex_pair_address' => $dexData['pair_address'] ?? null,

                    'dex_market_cap' => $dexData['market_cap'] ?? null,

                    'dex_liquidity' => $dexData['liquidity_usd'] ?? null,

                    'dex_pair_age_minutes' => $dexData['pair_age_minutes'] ?? null,

                    'raw_data' => array_merge($token, ['scanner_decision' => $paperEntryDecision]),

                    'scanned_at' => now(),
                ]);

                if ($classification['trade_eligible']) {
                    $dexAvailable = (bool) ($dexData['available'] ?? false);

                    /*
                    * For paper trading, Dex availability is not mandatory.
                    *
                    * If Dex has already indexed the token, use the Dex snapshot.
                    * If Dex is not available yet, use the fresh Birdeye snapshot
                    * as a provisional paper entry so we can measure very early trades.
                    */
                    $entrySource = $dexAvailable ? 'dex' : 'birdeye_provisional';

                    $entryMarketCap = $dexAvailable
                        ? (float) ($dexData['market_cap'] ?? 0)
                        : (float) ($token['marketCap'] ?? 0);

                    $entryPrice = $dexAvailable
                        ? ($dexData['price_usd'] ?? null)
                        : ($token['price'] ?? null);

                    $entryLiquidity = $dexAvailable
                        ? ($dexData['liquidity_usd'] ?? null)
                        : ($token['liquidity'] ?? null);

                    $entryMove = $discoveryMarketCap > 0 && $entryMarketCap > 0
                        ? (($entryMarketCap - $discoveryMarketCap) / $discoveryMarketCap) * 100
                        : null;

                    $maxChase = (float) $settings->get('scanner.max_chase_percent');

                    $paperEntryDecision['paper_entry_reason'] = match (true) {
                        ! config('services.trading.paper_trading', true) => 'Paper trading is disabled.',

                        $dexAvailable && ! ($dexData['requested_token_is_base'] ?? false) => 'Requested token is not the Dex pair base token.',

                        $entryMarketCap < 2_000 || $entryMarketCap > 20_000 => 'Current market cap is outside the new-token entry range.',

                        $entryMove !== null && $entryMove <= -30 => 'Entry rejected by collapse protection.',

                        $entryMove !== null && $entryMove > $maxChase => 'Entry rejected by chase protection.',

                        default => 'Entry checks passed.',
                    };

                    if ($paperEntryDecision['paper_entry_reason'] !== 'Entry checks passed.') {
                        $paperEntryDecision['paper_entry_status'] = 'rejected';
                    }

                    if ($paperEntryDecision['paper_entry_reason'] === 'Entry checks passed.') {
                        try {
                            $execution = $opportunities->qualify([
                                'chain' => $chain->value,
                                'address' => $address,
                                'symbol' => $symbol,
                                'name' => $name,
                                'discovery_market_cap' => $discoveryMarketCap,
                                'entry_market_cap' => $entryMarketCap,
                                'entry_price' => $entryPrice,
                                'entry_liquidity' => $entryLiquidity,
                                'volume' => $token['v1m'] ?? null,
                                'move_since_discovery_percent' => $entryMove,
                                'scanner' => 'new-token',
                                'send_notification' => false,
                                'security_data' => [
                                    'status' => $securityUnavailable ? 'unavailable' : (($security['passed'] ?? null) === true ? 'passed' : 'failed'),
                                    'provider' => 'GoPlus',
                                    'passed' => $security['passed'] ?? null,
                                    'score' => $security['score'] ?? null,
                                    'risks' => $security['risks'] ?? [],
                                    'coverage' => $securityUnavailable ? 'GoPlus token-security data was unavailable.' : 'GoPlus Solana token-security evaluation.',
                                ],
                                'meta' => [
                                    'source' => 'new_token_scan',
                                    'classification' => $classification['classification'],
                                    'trade_eligible' => true,

                                    'entry_source' => $entrySource,

                                    'dex_confirmation' => $dexAvailable
                                        ? 'confirmed'
                                        : 'pending',

                                    'pair_address' => $dexData['pair_address'] ?? null,
                                    'dex' => $dexData['dex'] ?? null,
                                ],
                            ]);

                            $paperPosition = $execution['position'];
                            $paperBuyExecuted = $paperPosition?->wasRecentlyCreated ?? false;

                            $paperEntryDecision['paper_entry_status'] = match (true) {
                                $paperBuyExecuted => 'executed',
                                $paperPosition !== null => 'already_open',
                                $execution['opportunity']->status->value === 'pending_confirmation' => 'pending_confirmation',
                                $execution['opportunity']->status->value === 'ignored' => 'ignored',
                                default => 'signaled',
                            };

                            $paperEntryDecision['paper_entry_reason'] = match (true) {
                                $paperBuyExecuted => (
                                    $dexAvailable
                                        ? 'Funded paper position created using Dex entry data.'
                                        : 'Funded provisional paper position created using fresh Birdeye data; Dex confirmation pending.'
                                ),
                                $paperPosition !== null => 'A funded position is already open for this chain and address.',
                                $execution['opportunity']->status->value === 'pending_confirmation' => 'Qualified opportunity is waiting for confirmation.',
                                $execution['opportunity']->status->value === 'ignored' => 'Execution was blocked by the emergency kill switch.',
                                default => 'Qualified signal recorded without execution.',
                            };

                            $this->info(
                                $paperBuyExecuted
                                    ? "PAPER BUY EXECUTED: {$symbol} | Entry source: {$entrySource}"
                                    : "PAPER BUY SKIPPED: {$symbol} | position already open"
                            );
                        } catch (Throwable $exception) {
                            $paperEntryDecision['paper_entry_status'] = 'rejected';
                            $paperEntryDecision['paper_entry_reason'] = $exception->getMessage();

                            $this->warn(
                                "PAPER BUY REJECTED: {$symbol} | {$exception->getMessage()}"
                            );
                        }
                    }
                }

                $this->persistScannerDecision($scan, $paperEntryDecision);

                if ($score >= $watchlistThreshold) {
                    $marketCap = (float) ($token['marketCap'] ?? 0);
                    $liquidity = (float) ($token['liquidity'] ?? 0);
                    $holders = (int) ($token['holder'] ?? 0);
                    $buys = (int) ($token['buy1m'] ?? 0);
                    $sells = (int) ($token['sell1m'] ?? 0);
                    $wallets = (int) ($token['uniqueWallet5m'] ?? 0);
                    $change = (float) ($token['priceChange5mPercent'] ?? 0);

                    $level = $classification['label'];
                    $entryMessage = $classification['trade_eligible']
                        ? (
                            $paperBuyExecuted
                                ? (
                                    ($dexData['available'] ?? false)
                                        ? 'Strong candidate — funded paper entry executed using Dex-confirmed market data.'
                                        : 'Strong candidate — provisional funded paper entry executed using fresh Birdeye data. Dex confirmation pending.'
                                )
                                : 'Strong candidate — entry was not executed: '.$paperEntryDecision['paper_entry_reason']
                        )
                        : $classification['no_trade_message'];

                    $message =
                        "{$level}\n\n".
                        "🚨 <b>New Solana Candidate</b>\n\n".
                        "<b>{$symbol}</b> — {$name}\n\n".
                        "Score: <b>{$score}/100</b>\n".
                        'Security: <b>'.
                        (
                            $securityUnavailable
                                ? '⚠️ UNVERIFIED'
                                : $security['score'].'/100'
                        ).
                        "</b>\n\n".
                        'Market Cap: $'.number_format($marketCap, 2)."\n".
                        'Liquidity: $'.number_format($liquidity, 2)."\n".
                        "Holders: {$holders}\n".
                        "Buys 1m: {$buys}\n".
                        "Sells 1m: {$sells}\n".
                        "Unique wallets 5m: {$wallets}\n".
                        'Price change 5m: '.number_format($change, 2)."%\n\n".
                        "<code>{$address}</code>\n\n".
                        $entryMessage."\n\n".
                        '⚠️ Scanner alert only — not a buy recommendation.';

                    try {
                        $telegram->send($message);
                        $this->info("Telegram alert sent for {$symbol}");
                    } catch (Throwable $e) {
                        $this->error(
                            "Telegram failed for {$symbol}: ".$e->getMessage()
                        );
                    }

                    if ($paperBuyExecuted && $paperPosition) {
                        $entries->sendBuyNotification($paperPosition);
                    }
                }

                $this->info(
                    sprintf(
                        '%s | %s... | MC: $%s | Liquidity: $%s | Score: %d',
                        $token['symbol'] ?? 'UNKNOWN',
                        substr($address, 0, 8),
                        number_format((float) ($token['marketCap'] ?? 0), 2),
                        number_format((float) ($token['liquidity'] ?? 0), 2),
                        $score
                    )
                );

            } catch (Throwable $e) {
                $this->error(
                    "{$symbol}: {$e->getMessage()}"
                );
            }
        }

        $this->newLine();
        $this->info('Scan finished.');

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

        /*
        * Liquidity quality
        */
        if ($marketCap > 0) {
            $liquidityRatio = $liquidity / $marketCap;

            if ($liquidityRatio >= 0.50) {
                $score += 20;
            } elseif ($liquidityRatio >= 0.25) {
                $score += 10;
            }
        }

        /*
        * Holder participation
        */
        if ($holders >= 50) {
            $score += 20;
        } elseif ($holders >= 20) {
            $score += 15;
        } elseif ($holders >= 10) {
            $score += 10;
        }

        /*
        * Buy pressure
        */
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

        /*
        * Unique wallet participation
        */
        if ($wallets >= 30) {
            $score += 20;
        } elseif ($wallets >= 15) {
            $score += 15;
        } elseif ($wallets >= 10) {
            $score += 10;
        }

        /*
        * Positive momentum — but don't reward
        * ridiculously vertical moves too heavily.
        */
        if ($priceChange >= 2 && $priceChange <= 20) {
            $score += 15;

        } elseif ($priceChange > 0 && $priceChange < 2) {
            $score += 5;

        } elseif ($priceChange > 50) {
            // Extremely vertical pumps are dangerous.
            $score -= 10;

        } elseif ($priceChange <= -50) {
            // Severe collapse.
            $score -= 25;

        } elseif ($priceChange <= -20) {
            // Strong downward momentum.
            $score -= 15;

        } elseif ($priceChange <= -5) {
            // Noticeable selloff.
            $score -= 10;
        }

        return max(0, min($score, 100));
    }

    private function pause(int $microseconds): void
    {
        if (! app()->environment('testing')) {
            usleep($microseconds);
        }
    }

    /** @param array<string, mixed> $decision */
    private function persistScannerDecision(TokenScan $scan, array $decision): void
    {
        $rawData = is_array($scan->raw_data) ? $scan->raw_data : [];
        $rawData['scanner_decision'] = $decision;

        $scan->forceFill(['raw_data' => $rawData])->save();
        $scan->refresh();

        if (! is_array(data_get($scan->raw_data, 'scanner_decision'))) {
            throw new RuntimeException("Scanner decision was not persisted for TokenScan {$scan->id}.");
        }

        $history = $scan->histories()->latest('id')->first();

        if ($history) {
            $historyRawData = is_array($history->raw_data) ? $history->raw_data : [];
            $historyRawData['scanner_decision'] = $decision;
            $history->forceFill(['raw_data' => $historyRawData])->save();
        }
    }
}
