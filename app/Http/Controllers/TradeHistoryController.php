<?php

namespace App\Http\Controllers;

use App\Http\Requests\TradeHistoryRequest;
use App\Services\PaperTradeHistoryService;
use Illuminate\Contracts\View\View;

class TradeHistoryController extends Controller
{
    public function __invoke(
        TradeHistoryRequest $request,
        PaperTradeHistoryService $history,
    ): View {
        $filters = $request->filters();

        return view('trades.index', [
            'filters' => $filters,
            'trades' => $history->paginate($filters, $request->integer('page', 1)),
            'performance' => $history->performanceSummary(),
        ]);
    }
}
