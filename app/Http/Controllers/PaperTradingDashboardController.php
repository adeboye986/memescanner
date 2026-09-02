<?php

namespace App\Http\Controllers;

use App\Chain;
use App\Models\PaperPosition;
use App\Services\DashboardCommandRegistry;
use App\Services\PaperWalletService;
use App\Services\SystemActivityService;
use Illuminate\Contracts\View\View;

class PaperTradingDashboardController extends Controller
{
    public function __invoke(
        DashboardCommandRegistry $commands,
        SystemActivityService $activities,
        PaperWalletService $wallets,
    ): View {
        $paperWallets = collect(Chain::cases())
            ->mapWithKeys(fn (Chain $chain): array => [$chain->value => $wallets->default($chain)]);
        $positions = PaperPosition::query()
            ->where('status', 'open')
            ->where('initial_investment_sol', '>', 0)
            ->orderBy('entry_at')
            ->get()
            ->map(fn (PaperPosition $position): array => $this->presentPosition($position));

        return view('dashboard', [
            'wallets' => $paperWallets,
            'positions' => $positions,
            'dashboardActions' => $commands->all(),
            'currentActivity' => $activities->currentManualData(),
            'recentActivities' => $activities->recentData(),
            'runningActions' => $activities->runningActions(),
            'systemStatus' => $activities->systemStatus(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPosition(PaperPosition $position): array
    {
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
            (bool) $position->tp_2x_hit => '+200% PROFIT PROTECTED — HOLDING',
            (bool) $position->tp_50_hit => '+100% PROFIT PROTECTED — HOLDING',
            default => 'UNPROTECTED — STOP -10%',
        };
        $protectedFloorMultiple = match (true) {
            (bool) $position->tp_2x_hit => 3.0,
            (bool) $position->tp_50_hit => 2.0,
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
            'levels' => [
                'stop_loss' => $entryMarketCap * 0.90,
                'profit_1x' => $entryMarketCap * 2,
                'informational_1_5x' => $entryMarketCap * 2.5,
                'profit_2x' => $entryMarketCap * 3,
            ],
        ];
    }
}
