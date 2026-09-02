<?php

namespace App\Http\Controllers;

use App\Models\TradeOpportunity;
use App\Services\OpportunityActionService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class IgnoreOpportunityController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, TradeOpportunity $opportunity, OpportunityActionService $actions): RedirectResponse
    {
        try {
            $changed = $actions->ignore($opportunity, $request->user());

            return to_route('opportunities.show', $opportunity)->with(
                'success',
                $changed ? 'Opportunity ignored.' : 'Opportunity was already ignored.',
            );
        } catch (DomainException $exception) {
            return to_route('opportunities.show', $opportunity)->with('error', $exception->getMessage());
        }
    }
}
