<?php

namespace App\Console\Commands;

use App\Services\DexScreenerService;
use Illuminate\Console\Command;

class TestDexConfirmation extends Command
{
    protected $signature = 'tokens:test-dex {address}';

    protected $description =
        'Test DexScreener two-snapshot confirmation without Birdeye';

    public function handle(
        DexScreenerService $dexscreener
    ): int {
        $address = $this->argument('address');

        $this->info(
            "Testing Dex confirmation for {$address}"
        );

        /*
         * Snapshot #1
         */
        try {
            $first =
                $dexscreener->analyzeToken($address);
        } catch (\Throwable $e) {
            $this->error(
                'First Dex request failed: ' .
                $e->getMessage()
            );

            return self::FAILURE;
        }

        if (!($first['available'] ?? false)) {
            $this->error(
                'No DexScreener pair available.'
            );

            return self::FAILURE;
        }

        $firstMc =
            (float) ($first['market_cap'] ?? 0);

        $firstLiquidityRaw =
            $first['liquidity_usd'] ?? null;

        $firstLiquidity =
            $firstLiquidityRaw !== null
                ? (float) $firstLiquidityRaw
                : null;

        $this->info(
            sprintf(
                'DEX #1 | %s | MC: %s | Liquidity: %s | Age: %s min',
                strtoupper(
                    $first['dex'] ?? 'unknown'
                ),
                $firstMc > 0
                    ? '$' . number_format($firstMc, 2)
                    : 'N/A',
                $firstLiquidity !== null
                    ? '$' . number_format(
                        $firstLiquidity,
                        2
                    )
                    : 'N/A',
                isset($first['pair_age_minutes'])
                    ? number_format(
                        $first['pair_age_minutes'],
                        0
                    )
                    : 'N/A'
            )
        );

        $this->line(
            'Waiting 8 seconds...'
        );

        sleep(8);

        /*
         * Snapshot #2
         */
        try {
            $second =
                $dexscreener->analyzeToken($address);
        } catch (\Throwable $e) {
            $this->error(
                'Second Dex request failed: ' .
                $e->getMessage()
            );

            return self::FAILURE;
        }

        if (!($second['available'] ?? false)) {
            $this->error(
                'Dex pair unavailable on second snapshot.'
            );

            return self::FAILURE;
        }

        $secondMc =
            (float) ($second['market_cap'] ?? 0);

        $secondLiquidityRaw =
            $second['liquidity_usd'] ?? null;

        $secondLiquidity =
            $secondLiquidityRaw !== null
                ? (float) $secondLiquidityRaw
                : null;

        $this->info(
            sprintf(
                'DEX #2 | %s | MC: %s | Liquidity: %s',
                strtoupper(
                    $second['dex'] ?? 'unknown'
                ),
                $secondMc > 0
                    ? '$' . number_format($secondMc, 2)
                    : 'N/A',
                $secondLiquidity !== null
                    ? '$' . number_format(
                        $secondLiquidity,
                        2
                    )
                    : 'N/A'
            )
        );

        /*
         * Market-cap movement.
         */
        if (
            $firstMc > 0 &&
            $secondMc > 0
        ) {
            $mcChange =
                (
                    ($secondMc - $firstMc) /
                    $firstMc
                ) * 100;

            $this->line(
                '8s MC change: ' .
                number_format($mcChange, 2) .
                '%'
            );

            if ($mcChange <= -30) {
                $this->error(
                    'REJECT: Market cap collapsed.'
                );

                return self::SUCCESS;
            }

            if ($mcChange >= 100) {
                $this->error(
                    'REJECT: Market cap spiked too quickly.'
                );

                return self::SUCCESS;
            }
        }

        /*
         * Liquidity retention.
         */
        if (
            $firstLiquidity !== null &&
            $firstLiquidity > 0 &&
            $secondLiquidity !== null
        ) {
            $retention =
                $secondLiquidity /
                $firstLiquidity;

            $this->line(
                'Liquidity retained: ' .
                number_format(
                    $retention * 100,
                    2
                ) .
                '%'
            );

            if ($retention < 0.50) {
                $this->error(
                    'REJECT: Liquidity collapsed.'
                );

                return self::SUCCESS;
            }
        }

        $this->info(
            'DEX CONFIRMATION PASSED'
        );

        return self::SUCCESS;
    }
}