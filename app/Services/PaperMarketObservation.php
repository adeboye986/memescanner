<?php

namespace App\Services;

use App\Models\PaperPosition;
use Illuminate\Support\Carbon;
use Throwable;

class PaperMarketObservation
{
    public function __construct(private PaperStrategyService $strategies) {}

    public static function positive(mixed $value): ?float
    {
        return (is_int($value) || is_float($value) || is_string($value)) && is_numeric($value)
            && is_finite((float) $value) && (float) $value > 0 ? (float) $value : null;
    }

    /** No provider mark or pool reserve is an executable sell quote. */
    public function evaluate(PaperPosition $position, array $data): array
    {
        $policy = config('services.trading.paper_market.'.$position->chain->value, []);
        $diagnostics = [];
        $price = self::positive($data['price_usd'] ?? $data['price'] ?? null);
        $reportedCap = self::positive($data['market_cap'] ?? null);
        $entryCap = self::positive($position->entry_market_cap);
        $entryPrice = self::positive($position->entry_price);
        $ratioCap = $entryCap !== null && $entryPrice !== null && $price !== null
            ? self::positive($entryCap * ($price / $entryPrice)) : null;
        $cap = $reportedCap ?? $ratioCap;
        $liquidity = self::positive($data['liquidity_usd'] ?? null);
        $reasons = [];
        if (! ($data['available'] ?? false) || (! ($data['requested_token_is_base'] ?? false) && ! ($data['requested_token_identity_verified'] ?? false)) || $cap === null || $entryCap === null) {
            $reasons[] = $data['reason'] ?? 'invalid_valuation';
        }
        if (($policy['max_observation_age_seconds'] ?? null) !== null) {
            try {
                $fetched = isset($data['fetched_at']) ? Carbon::parse($data['fetched_at']) : null;
                if ($fetched === null || $fetched->gt(now()) || $fetched->lt(now()->subSeconds(max(1, (int) $policy['max_observation_age_seconds'])))) {
                    $reasons[] = 'stale_or_missing_fetch_time';
                }
            } catch (Throwable) {
                $reasons[] = 'invalid_fetch_time';
            }
        }
        if (($cap !== null && $cap >= 1e16) || ($liquidity !== null && $liquidity >= 1e16) || ($price !== null && $price >= 1e12)
            || ($cap !== null && $entryCap !== null && $cap / $entryCap >= 1e8)) {
            $reasons[] = 'valuation_outside_storage_range';
        }
        if (($policy['require_liquidity'] ?? false) && ($liquidity === null || $liquidity < (float) ($policy['minimum_liquidity_usd'] ?? 0))) {
            $reasons[] = $liquidity === null ? 'liquidity_unavailable' : 'liquidity_below_configured_minimum';
        }
        /** Supply changes can explain disagreement; this diagnostic never blocks an exit. */
        if (($policy['valuation_discrepancy_ratio'] ?? null) !== null && $reportedCap !== null && $ratioCap !== null
            && max($reportedCap, $ratioCap) / min($reportedCap, $ratioCap) > max(1, (float) $policy['valuation_discrepancy_ratio'])) {
            $diagnostics[] = 'market_cap_price_ratio_discrepancy_possible_supply_change';
        }
        $previous = self::positive($position->last_market_cap) ?? $entryCap;
        /** Configured decline diagnostics never discard a mark solely for its size. */
        $collapse = ($policy['decline_diagnostic_percent'] ?? null) !== null && $cap !== null && $previous !== null
            && (1 - $cap / $previous) * 100 >= (float) $policy['decline_diagnostic_percent'];
        if ($collapse) {
            $diagnostics[] = 'severe_decline';
        }

        $stopLoss = $this->strategies->forPosition($position)['stop_loss_multiple'];
        $multiple = $cap !== null && $entryCap !== null ? self::positive($cap / $entryCap) : null;
        if ($multiple === null) {
            $reasons[] = 'invalid_valuation_multiple';
        }

        return ['observed_multiple' => $multiple, 'stop_loss_trigger_multiple' => $stopLoss,
            'stop_loss_threshold_breached' => $multiple !== null && $multiple <= $stopLoss,
            'status' => $reasons !== [] ? 'unverified' : ($collapse ? 'severe_decline' : 'observed'),
            'reasons' => $reasons, 'diagnostics' => $diagnostics, 'severe_decline' => $collapse, 'checked_at' => now()->toIso8601String(),
            'market_cap' => $cap, 'reported_market_cap' => $reportedCap, 'price_usd' => $price, 'liquidity_usd' => $liquidity,
            'valuation_source' => $reportedCap !== null ? 'provider_market_cap' : ($ratioCap !== null ? 'entry_price_ratio' : 'unavailable'),
            'valuation_assumption' => $reportedCap === null && $ratioCap !== null ? 'Entry valuation scaled by price; assumes unchanged circulating supply.' : null,
            'simulation_allowed' => $reasons === [], 'estimated_executable_fill' => null, 'execution_verified' => false,
            'fill_model' => 'observed_mark_without_slippage_or_depth',
            'price_token_side' => $data['price_token_side'] ?? 'base',
            'provider' => $data['provider'] ?? null, 'fetched_at' => $data['fetched_at'] ?? null,
            'provider_observed_at' => $data['provider_observed_at'] ?? null,
            'freshness_basis' => 'response_fetch_time_only; upstream_trade_time_unknown',
            'pair_address' => $data['pair_address'] ?? null, 'original_pair_address' => $data['original_pair_address'] ?? data_get($position->meta, 'pair_address'),
            'pool_switched' => $data['pool_switched'] ?? false, 'selection_reason' => $data['selection_reason'] ?? null,
            'original_liquidity_usd' => $data['original_liquidity_usd'] ?? null,
            'minimum_liquidity_usd' => $policy['minimum_liquidity_usd'] ?? null,
            'pool_execution_limitation' => $data['pool_execution_limitation'] ?? 'Pool marks do not establish an executable fill.',
            'fallback_reason' => $data['fallback_reason'] ?? null];
    }
}
