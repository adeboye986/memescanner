<?php

namespace App\Http\Controllers;

use App\Models\PaperPosition;
use App\Services\PaperTradeExitService;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

class ClosePaperTradeController extends Controller
{
    public function __invoke(
        PaperPosition $position,
        PaperTradeExitService $exitService,
    ): RedirectResponse {
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

        $response = back()->with('success', "{$result['position']->symbol} was closed successfully.");

        if ($result['notification_error'] !== null) {
            $response->with('warning', 'The position closed, but its Telegram notification failed.');
        }

        return $response;
    }
}
