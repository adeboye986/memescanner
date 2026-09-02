<?php

namespace App\Http\Controllers;

use App\Models\TradeOpportunity;
use App\Services\OpportunityActionService;
use DomainException;
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
        try {
            $position = $actions->approve($opportunity, $request->user());

            return to_route('opportunities.show', $opportunity)
                ->with('success', "{$opportunity->symbol} was approved and paper position #{$position->id} was created.");
        } catch (DomainException|RuntimeException $exception) {
            return to_route('opportunities.show', $opportunity)->with('error', $exception->getMessage());
        }
    }
}
