<?php

namespace App\Http\Controllers;

use App\Models\EthereumSwapAttempt;
use App\Models\TradeOpportunity;
use App\Services\OpportunityActionService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ApproveOpportunityController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, TradeOpportunity $opportunity, OpportunityActionService $actions): RedirectResponse
    {
        abort_unless($opportunity->user_id === $request->user()->id || ($request->user()->is_admin && $opportunity->user_id === null), 404);

        try {
            $result = $actions->approve($opportunity, $request->user(), $request->only(['sell_amount_wei', 'slippage_bps']));

            if ($result instanceof EthereumSwapAttempt) {
                return to_route('opportunities.show', $opportunity)
                    ->with('success', 'Ethereum execution reserved. No transaction has been prepared, signed, or submitted.');
            }

            return to_route('opportunities.show', $opportunity)
                ->with('success', "{$opportunity->symbol} was approved and paper position #{$result->id} was created.");
        } catch (QueryException $exception) {
            report($exception);

            return to_route('opportunities.show', $opportunity)
                ->with('error', 'Approval could not be completed because of a temporary database problem. Please retry.');
        } catch (DomainException|RuntimeException $exception) {
            return to_route('opportunities.show', $opportunity)->with('error', $exception->getMessage());
        }
    }
}
