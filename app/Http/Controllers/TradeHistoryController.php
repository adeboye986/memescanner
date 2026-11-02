<?php

namespace App\Http\Controllers;

use App\Http\Requests\TradeHistoryRequest;
use App\Services\TradingDashboardService;
use App\Services\UserTradingPreferenceService;
use Illuminate\Contracts\View\View;

class TradeHistoryController extends Controller
{
    public function __invoke(
        TradeHistoryRequest $request,
        TradingDashboardService $trading,
        UserTradingPreferenceService $preferences,
    ): View {
        $filters = $request->filters();
        $user = $request->user();
        $preference = $preferences->forUser($user);
        $tradingData = $trading->forUser($user, $filters, $request->integer('page', 1));

        return view('trades.dashboard', [
            'filters' => $filters,
            'trading' => $tradingData,
            'trades' => $tradingData['history'],
            'executionMode' => $preference->execution_mode->value,
            'entryMode' => $preference->entry_mode->value,
        ]);
    }
}
