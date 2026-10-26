<?php

namespace Tests\Unit;

use App\Console\Commands\ScanMomentumTokens;
use App\Console\Commands\ScanNewTokens;
use App\Services\EthereumQualificationEvaluator;
use App\Services\NewTokenClassificationService;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class ScannerParityFixtureTest extends TestCase
{
    public function test_new_token_fixture_matches_legacy_scoring_and_qualification(): void
    {
        $fixture = $this->fixture();
        $command = (new ReflectionClass(ScanNewTokens::class))->newInstanceWithoutConstructor();
        $scoreMethod = new ReflectionMethod(ScanNewTokens::class, 'calculateScore');
        $classifications = new NewTokenClassificationService;

        foreach ($fixture['newToken'] as $entry) {
            $market = $entry['market'];
            $legacyMarket = [
                'marketCap' => $market['marketCap'],
                'liquidity' => $market['liquidity'],
                'holder' => $market['holders'],
                'buy1m' => $market['buys1m'],
                'sell1m' => $market['sells1m'],
                'uniqueWallet5m' => $market['uniqueWallets5m'],
                'priceChange5mPercent' => $market['priceChange5mPercent'],
            ];
            $score = $scoreMethod->invoke($command, $legacyMarket);
            $classification = $classifications->classify(
                $score,
                ! $entry['securityAvailable'],
            );
            $qualified = $market['marketCap'] >= 2_000
                && $market['marketCap'] <= 20_000
                && $market['liquidity'] >= 500
                && $market['holders'] >= 5
                && $entry['securityPassed'] === true
                && $classification['trade_eligible'];

            $this->assertSame($entry['expected']['score'], $score, $entry['name']);
            $this->assertSame($entry['expected']['qualified'], $qualified, $entry['name']);
        }
    }

    public function test_momentum_fixture_matches_legacy_discovery_ranking(): void
    {
        $fixture = $this->fixture()['momentum'];
        $market = $fixture['market'];
        $command = (new ReflectionClass(ScanMomentumTokens::class))->newInstanceWithoutConstructor();
        $rankMethod = new ReflectionMethod(ScanMomentumTokens::class, 'calculateDexDiscoveryRank');
        $legacyMarket = [
            'market_cap' => $market['marketCap'],
            'liquidity' => $market['liquidityUsd'],
            'volume_5m_usd' => $market['volume5m'],
            'trade_5m_count' => $market['buys5m'] + $market['sells5m'],
            'buy_5m_count' => $market['buys5m'],
            'sell_5m_count' => $market['sells5m'],
            'price_change_5m' => $market['priceChange5m'],
        ];
        $rank = $rankMethod->invoke($command, $legacyMarket);
        $holderScore = $fixture['holderRisk']['score'] ?? 50;
        $preBirdeyeScore = max(0, min(100, (int) round(
            ($rank * 0.70) + ($holderScore * 0.30)
            - ($market['priceChange5m'] > 100 ? 15 : 0),
        )));
        $qualified = $market['available']
            && $market['requestedTokenIsBase']
            && $market['marketCap'] >= 5_000
            && $market['marketCap'] <= 100_000
            && ($market['liquidityUsd'] === null || $market['liquidityUsd'] >= 1_000)
            && $market['volume5m'] >= 500
            && ($market['buys5m'] + $market['sells5m']) >= 10
            && ($market['priceChange5m'] === null
                || ($market['priceChange5m'] > -40 && $market['priceChange5m'] <= 150));

        $this->assertSame($fixture['expected']['qualified'], $qualified);
        $this->assertSame($fixture['expected']['dexRank'], $rank);
        $this->assertSame($fixture['expected']['preBirdeyeScore'], $preBirdeyeScore);
    }

    public function test_ethereum_fixture_matches_legacy_qualification_service(): void
    {
        $fixture = $this->fixture()['ethereum'];
        $market = $fixture['market'];
        $legacyMarket = [
            'available' => $market['available'],
            'requested_token_is_base' => $market['requestedTokenIsBase'],
            'market_cap' => $market['marketCap'],
            'liquidity_usd' => $market['liquidityUsd'],
            'volume_5m' => $market['volume5m'],
        ];
        $evaluator = new EthereumQualificationEvaluator;

        $this->assertSame(
            $fixture['expected']['newToken'],
            $evaluator->qualifies('new-token', $legacyMarket),
        );
        $this->assertSame(
            $fixture['expected']['momentum'],
            $evaluator->qualifies('momentum', $legacyMarket),
        );
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $contents = file_get_contents(base_path('trading-engine/tests/fixtures/scanner-parity.json'));

        $this->assertIsString($contents);

        return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    }
}
