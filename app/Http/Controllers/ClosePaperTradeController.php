<?php

namespace App\Http\Controllers;

use App\Models\PaperPosition;
use App\Services\PaperTradeExitService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ClosePaperTradeController extends Controller
{
    public function __invoke(
        Request $request,
        PaperPosition $position,
        PaperTradeExitService $exitService,
    ): RedirectResponse {
        abort_unless($position->user_id === $request->user()->id || ($request->user()->is_admin && $position->user_id === null), 404);
        if ($position->status !== 'open') {
            return back()->with('error', 'This paper position is already closed.');
        }

        if ((float) $position->initial_investment_sol <= 0) {
            return back()->with('error', 'Only funded paper positions can be closed.');
        }

        try {
            $result = $exitService->closeManually($position);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $success = match ($result['price_source']) {
            'last_known_market' => "{$result['position']->symbol} was closed successfully using its last known market value because fresh Dex data was unavailable.",
            'entry_fallback' => "{$result['position']->symbol} was closed successfully using its entry value because fresh and last known market data were unavailable.",
            default => "{$result['position']->symbol} was closed successfully.",
        };
        $response = back()->with('success', $success);

        if ($result['price_source'] !== 'fresh_market') {
            $response->with('warning', 'Fallback valuation was used for this manual paper-trade close.');
        }

        if ($result['notification_error'] !== null) {
            $warning = $result['price_source'] !== 'fresh_market'
                ? 'Fallback valuation was used. The position closed, but its Telegram notification failed.'
                : 'The position closed, but its Telegram notification failed.';
            $response->with('warning', $warning);
        }

        return $response;
    }
}
