<?php

namespace App\Services;

use App\Chain;
use App\Models\TokenScan;
use App\Models\User;
use App\Services\Chains\ChainManager;

class EthereumScannerService
{
    public function __construct(
        private ChainManager $chains,
        private TradeOpportunityService $opportunities,
        private EthereumQualificationEvaluator $qualification,
        private ScanExecutionRecorder $scanExecutions,
    ) {}

    /** @return array{profiles: int, qualified: int, positions: int, unavailable_checks: list<string>} */
    public function scan(string $scanner, ?User $requestingUser = null, ?int $systemActivityId = null): array
    {
        $adapter = $this->chains->for(Chain::Ethereum);

        try {
            $profiles = $adapter->latestProfiles(30);
        } catch (\Throwable $exception) {
            $this->scanExecutions->providerFailed($systemActivityId, 'DISCOVERY_PROVIDER_UNAVAILABLE');

            throw $exception;
        }

        $this->scanExecutions->discovered($systemActivityId, count($profiles));
        $qualified = 0;
        $positions = 0;

        foreach ($profiles as $profile) {
            $this->scanExecutions->examined($systemActivityId);
            $address = strtolower((string) ($profile['tokenAddress'] ?? ''));

            if ($address === '') {
                continue;
            }

            try {
                $market = $adapter->marketData($address);
            } catch (\Throwable $exception) {
                $this->scanExecutions->providerFailed($systemActivityId, 'MARKET_PROVIDER_UNAVAILABLE');
                $this->scanExecutions->candidateFailed($systemActivityId, 'CANDIDATE_PROCESSING_FAILED');

                throw $exception;
            }

            if (! ($market['available'] ?? false) || ! ($market['requested_token_is_base'] ?? false)) {
                continue;
            }

            $marketCap = (float) ($market['market_cap'] ?? 0);
            $liquidity = (float) ($market['liquidity_usd'] ?? 0);
            $volume = (float) ($market['volume_5m'] ?? 0);
            $isQualified = $this->qualification->qualifies($scanner, $market);

            if (! $isQualified) {
                continue;
            }

            $qualified++;
            $this->scanExecutions->qualifiedCandidate($systemActivityId);
            $raw = $market['raw'] ?? [];
            $symbol = $market['base_token_symbol'] ?? data_get($raw, 'baseToken.symbol');
            $name = data_get($raw, 'baseToken.name');

            TokenScan::query()->updateOrCreate(
                ['chain' => Chain::Ethereum->value, 'address' => $address],
                [
                    'symbol' => $symbol,
                    'name' => $name,
                    'price' => $market['price_usd'] ?? null,
                    'market_cap' => $marketCap,
                    'liquidity' => $liquidity,
                    'volume_1m' => $volume,
                    'buys_1m' => $market['buys_5m'] ?? 0,
                    'sells_1m' => $market['sells_5m'] ?? 0,
                    'score' => 0,
                    'security_passed' => false,
                    'security_risks' => $adapter->unavailableSecurityChecks(),
                    'raw_data' => array_merge($raw, ['security_status' => 'unavailable']),
                    'first_seen_at' => now(),
                    'last_scanned_at' => now(),
                ],
            );

            if (! config('services.trading.paper_trading', true)) {
                continue;
            }

            try {
                $execution = $this->opportunities->qualify([
                    'chain' => Chain::Ethereum->value,
                    'address' => $address,
                    'symbol' => $symbol,
                    'name' => $name,
                    'discovery_market_cap' => $marketCap,
                    'entry_market_cap' => $marketCap,
                    'entry_price' => $market['price_usd'] ?? null,
                    'entry_liquidity' => $liquidity,
                    'volume' => $volume,
                    'move_since_discovery_percent' => 0,
                    'scanner' => $scanner,
                    'send_notification' => true,
                    'security_data' => [
                        'status' => 'unavailable',
                        'coverage' => 'No Ethereum token-security provider is configured.',
                        'market_validation' => [
                            'provider' => 'DexScreener',
                            'requested_token_is_base' => true,
                            'pair_available' => true,
                        ],
                    ],
                    'meta' => [
                        'pair_address' => $market['pair_address'] ?? null,
                        'dex' => $market['dex'] ?? null,
                        'security_status' => 'unavailable',
                        'unavailable_security_checks' => $adapter->unavailableSecurityChecks(),
                    ],
                ], $requestingUser, $systemActivityId);
            } catch (\Throwable $exception) {
                $this->scanExecutions->downstreamFailed($systemActivityId, 'OPPORTUNITY_HANDLING_FAILED');

                throw $exception;
            }

            if ($execution['position']?->wasRecentlyCreated) {
                $positions++;
            }
        }

        return [
            'profiles' => count($profiles),
            'qualified' => $qualified,
            'positions' => $positions,
            'unavailable_checks' => $adapter->unavailableSecurityChecks(),
        ];
    }
}
