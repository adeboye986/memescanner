<?php

namespace App\Services\TradingEngine;

use App\Chain;
use App\Models\TradeOpportunity;
use LogicException;

class TradingEngineOpportunityCommandFactory
{
    private const ETHEREUM_MAINNET_ID = 'eip155:1';

    private const SOLANA_MAINNET_ID = 'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp';

    public function __construct(private CanonicalDecimal $decimals) {}

    /**
     * @return array{payload: array<string, mixed>, idempotency_key: string}
     */
    public function make(TradeOpportunity $opportunity): array
    {
        if (! $opportunity->exists || $opportunity->getKey() === null || $opportunity->user_id === null) {
            throw new LogicException('Only persisted user-owned opportunities can be exported.');
        }

        if (! in_array($opportunity->scanner, ['new-token', 'momentum'], true)) {
            throw new LogicException('The opportunity scanner is not exportable.');
        }

        $qualification = (array) $opportunity->qualification_data;
        $meta = (array) ($qualification['meta'] ?? []);
        $marketProvider = $this->marketProvider($opportunity, $meta);
        $payload = [
            'schema_version' => 1,
            'source' => [
                'system' => 'meme-scanner-laravel',
                'opportunity_id' => (string) $opportunity->getKey(),
                'discovery_key' => $this->requiredDiscoveryKey($opportunity),
                'scanner' => $opportunity->scanner,
            ],
            'subject' => [
                'control_plane_user_id' => (string) $opportunity->user_id,
            ],
            'network' => [
                'id' => $opportunity->chain === Chain::Solana
                    ? self::SOLANA_MAINNET_ID
                    : self::ETHEREUM_MAINNET_ID,
            ],
            'asset' => array_filter([
                'address' => $opportunity->address,
                'symbol' => $this->optionalString($opportunity->symbol),
                'name' => $this->optionalString($opportunity->name),
            ], fn (mixed $value): bool => $value !== null),
            'market_snapshot' => $this->marketSnapshot($opportunity, $meta, $marketProvider),
            'qualification' => $this->qualification($opportunity, $qualification, $meta),
        ];
        $security = $this->security($opportunity, $meta);

        if ($security !== null) {
            $payload['security'] = $security;
        }

        return [
            'payload' => $payload,
            'idempotency_key' => 'opportunity:record:laravel:'.$opportunity->getKey().':v1',
        ];
    }

    /** @param array<string, mixed> $meta */
    private function marketProvider(TradeOpportunity $opportunity, array $meta): string
    {
        if ($opportunity->chain === Chain::Ethereum || $opportunity->scanner === 'momentum') {
            return 'dexscreener';
        }

        return ($meta['entry_source'] ?? null) === 'dex' ? 'dexscreener' : 'birdeye';
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function marketSnapshot(
        TradeOpportunity $opportunity,
        array $meta,
        string $marketProvider,
    ): array {
        $market = [];
        $measurements = [
            'price_usd' => 'price',
            'market_cap_usd' => 'market_cap',
            'liquidity_usd' => 'liquidity',
        ];

        foreach ($measurements as $target => $source) {
            $value = $this->decimals->normalize($opportunity->getRawOriginal($source));

            if ($value !== null) {
                $market[$target] = ['value' => $value, 'provider' => $marketProvider];
            }
        }

        $volume = $this->decimals->normalize($opportunity->getRawOriginal('volume'));

        if ($volume !== null) {
            $market['volume_usd'] = [
                'value' => $volume,
                'provider' => $opportunity->scanner === 'new-token' && $opportunity->chain === Chain::Solana
                    ? 'birdeye'
                    : 'dexscreener',
                'window' => $opportunity->scanner === 'new-token' && $opportunity->chain === Chain::Solana
                    ? '1m'
                    : '5m',
            ];
        }

        if ($this->optionalString($opportunity->pair_address) !== null) {
            $market['pair'] = array_filter([
                'address' => $opportunity->pair_address,
                'dex' => $this->optionalString($meta['dex'] ?? null),
                'provider' => 'dexscreener',
            ], fn (mixed $value): bool => $value !== null);
        }

        return $market;
    }

    /**
     * @param  array<string, mixed>  $qualification
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function qualification(
        TradeOpportunity $opportunity,
        array $qualification,
        array $meta,
    ): array {
        if ($opportunity->qualified_at === null) {
            throw new LogicException('The opportunity qualification timestamp is missing.');
        }

        return array_filter([
            'qualified_at' => $opportunity->qualified_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'discovery_market_cap_usd' => $this->decimals->normalize(
                $qualification['discovery_market_cap'] ?? null,
            ),
            'move_since_discovery_percent' => $this->decimals->normalize(
                $qualification['move_since_discovery_percent'] ?? null,
                true,
            ),
            'classification' => $this->optionalString($meta['classification'] ?? null),
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>|null
     */
    private function security(TradeOpportunity $opportunity, array $meta): ?array
    {
        $source = $opportunity->security_data;

        if (! is_array($source) || $source === []) {
            return null;
        }

        $status = $source['status'] ?? null;

        if (! in_array($status, ['passed', 'failed', 'unavailable'], true)) {
            return null;
        }

        $security = ['status' => $status];
        $provider = match (mb_strtolower((string) ($source['provider'] ?? ''))) {
            'goplus' => 'goplus',
            'solana rpc holder analysis' => 'solana_rpc_holder_analysis',
            default => null,
        };

        if ($provider !== null) {
            $security['provider'] = $provider;
        }

        if (is_bool($source['passed'] ?? null)) {
            $security['passed'] = $source['passed'];
        }

        if (is_int($source['score'] ?? null) && $source['score'] >= 0 && $source['score'] <= 100) {
            $security['score'] = $source['score'];
        }

        if (is_array($source['risks'] ?? null)) {
            $security['risks'] = array_values(array_filter(
                $source['risks'],
                fn (mixed $risk): bool => is_string($risk),
            ));
        }

        if ($this->optionalString($source['coverage'] ?? null) !== null) {
            $security['coverage'] = $source['coverage'];
        }

        $holder = $this->holderConcentration((array) ($source['holder_concentration'] ?? []));

        if ($holder !== []) {
            $security['holder_concentration'] = $holder;
        }

        $validation = (array) ($source['market_validation'] ?? []);

        if (is_bool($validation['requested_token_is_base'] ?? null)
            && is_bool($validation['pair_available'] ?? null)) {
            $security['market_validation'] = [
                'provider' => 'dexscreener',
                'requested_token_is_base' => $validation['requested_token_is_base'],
                'pair_available' => $validation['pair_available'],
            ];
        }

        if (is_array($meta['unavailable_security_checks'] ?? null)) {
            $security['unavailable_checks'] = array_values(array_filter(
                $meta['unavailable_security_checks'],
                fn (mixed $check): bool => is_string($check) && $check !== '',
            ));
        }

        return $security;
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, string>
     */
    private function holderConcentration(array $source): array
    {
        $holder = [];
        $fields = [
            'largest_holder_percentage' => 'largest_holder_percent',
            'top_5_percentage' => 'top_5_percent',
            'top_10_percentage' => 'top_10_percent',
        ];

        foreach ($fields as $sourceKey => $targetKey) {
            $value = $this->decimals->normalize($source[$sourceKey] ?? null);

            if ($value !== null) {
                $holder[$targetKey] = $value;
            }
        }

        if ($this->optionalString($source['risk_level'] ?? null) !== null) {
            $holder['risk_level'] = $source['risk_level'];
        }

        return $holder;
    }

    private function requiredDiscoveryKey(TradeOpportunity $opportunity): string
    {
        $key = $opportunity->discovery_key;

        if (! is_string($key) || preg_match('/^[0-9a-f]{64}$/D', $key) !== 1) {
            throw new LogicException('The opportunity discovery key is invalid.');
        }

        return $key;
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
