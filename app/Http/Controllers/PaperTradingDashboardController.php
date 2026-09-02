<?php

namespace App\Http\Controllers;

use App\Chain;
use App\Models\PaperPosition;
use App\Models\TradeOpportunity;
use App\Services\ApplicationSettingsService;
use App\Services\DashboardCommandRegistry;
use App\Services\IntegrationStatusService;
use App\Services\PaperStrategyService;
use App\Services\PaperWalletService;
use App\Services\SystemActivityService;
use Illuminate\Contracts\View\View;

class PaperTradingDashboardController extends Controller
{
    public function __invoke(
        DashboardCommandRegistry $commands,
        SystemActivityService $activities,
        PaperWalletService $wallets,
        PaperStrategyService $strategies,
        ApplicationSettingsService $settings,
        IntegrationStatusService $integrations,
    ): View {
        $paperWallets = collect(Chain::cases())
            ->mapWithKeys(fn (Chain $chain): array => [$chain->value => $wallets->default($chain)]);
        $positions = PaperPosition::query()
            ->where('status', 'open')
            ->where('initial_investment_sol', '>', 0)
            ->orderBy('entry_at')
            ->get()
            ->map(fn (PaperPosition $position): array => $this->presentPosition($position, $strategies));

        return view('dashboard', [
            'wallets' => $paperWallets,
            'positions' => $positions,
            'dashboardActions' => $commands->all(),
            'currentActivity' => $activities->currentManualData(),
            'recentActivities' => $activities->recentData(),
            'runningActions' => $activities->runningActions(),
            'systemStatus' => $activities->systemStatus(),
            'paperStrategy' => $strategies->forNewPosition(),
            'executionMode' => (string) $settings->get('trading.execution_mode'),
            'entryMode' => (string) $settings->get('trading.entry_mode'),
            'opportunitySummary' => [
                'recent' => TradeOpportunity::query()->where('qualified_at', '>=', now()->subDay())->count(),
                'pending' => TradeOpportunity::query()->where('status', 'pending_confirmation')->count(),
            ],
            'integrationSummary' => $integrations->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPosition(PaperPosition $position, PaperStrategyService $strategies): array
    {
        $strategy = $strategies->forPosition($position);
        $entryMarketCap = (float) $position->entry_market_cap;
        $currentMarketCap = (float) ($position->last_market_cap ?: $entryMarketCap);
        $currentMultiple = $entryMarketCap > 0 ? $currentMarketCap / $entryMarketCap : 1.0;
        $remainingFraction = $position->remaining_fraction !== null
            ? (float) $position->remaining_fraction
            : 1.0;
        $remainingCostBasis = (float) ($position->remaining_investment_sol ?? 0);

        if ($remainingCostBasis <= 0 && $remainingFraction > 0) {
            $remainingCostBasis = (float) $position->initial_investment_sol * $remainingFraction;
        }

        $peakMarketCap = max($entryMarketCap, (float) ($position->peak_market_cap ?? 0));
        $currentValue = $remainingCostBasis * $currentMultiple;
        $protectionState = match (true) {
            (bool) $position->tp_2x_hit => '+'.$this->formatPercent($strategy['protection_level_2_percent']).'% PROFIT PROTECTED — HOLDING',
            (bool) $position->tp_50_hit => '+'.$this->formatPercent($strategy['protection_level_1_percent']).'% PROFIT PROTECTED — HOLDING',
            default => 'UNPROTECTED — STOP -'.$this->formatPercent($strategy['stop_loss_percent']).'%',
        };
        $protectedFloorMultiple = match (true) {
            (bool) $position->tp_2x_hit => $strategy['protection_level_2_multiple'],
            (bool) $position->tp_50_hit => $strategy['protection_level_1_multiple'],
            default => null,
        };

        return [
            'model' => $position,
            'entry_market_cap' => $entryMarketCap,
            'current_market_cap' => $currentMarketCap,
            'current_return' => ($currentMultiple - 1) * 100,
            'current_multiple' => $currentMultiple,
            'peak_multiple' => $entryMarketCap > 0 ? $peakMarketCap / $entryMarketCap : 1.0,
            'remaining_fraction' => $remainingFraction,
            'current_value' => $currentValue,
            'unrealized_pnl' => $currentValue - $remainingCostBasis,
            'protection_armed' => (bool) $position->tp_50_hit,
            'protection_state' => $protectionState,
            'protected_floor_multiple' => $protectedFloorMultiple,
            'currency' => $position->chain === Chain::Ethereum ? 'ETH' : 'SOL',
            'strategy' => $strategy,
            'levels' => [
                'stop_loss' => $entryMarketCap * $strategy['stop_loss_multiple'],
                'profit_1x' => $entryMarketCap * $strategy['protection_level_1_multiple'],
                'profit_2x' => $entryMarketCap * $strategy['protection_level_2_multiple'],
            ],
        ];
    }

    private function formatPercent(float $percent): string
    {
        return rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.');
    }
}
