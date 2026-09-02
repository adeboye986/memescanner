<?php

namespace App\Http\Controllers;

use App\Chain;
use App\Enums\EntryMode;
use App\Enums\TradeOpportunityStatus;
use App\Http\Requests\OpportunityIndexRequest;
use App\Models\TradeOpportunity;
use App\Services\OpportunityPresentationService;
use Illuminate\Contracts\View\View;

class OpportunityController extends Controller
{
    public function index(OpportunityIndexRequest $request): View
    {
        $filters = $request->validated();
        $opportunities = TradeOpportunity::query()
            ->with('paperPosition:id,symbol,status')
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['chain'] ?? null, fn ($query, string $chain) => $query->where('chain', $chain))
            ->when($filters['entry_mode'] ?? null, fn ($query, string $mode) => $query->where('entry_mode', $mode))
            ->latest('qualified_at')
            ->paginate(20)
            ->withQueryString();

        return view('opportunities.index', [
            'opportunities' => $opportunities,
            'filters' => $filters,
            'statuses' => TradeOpportunityStatus::cases(),
            'chains' => Chain::cases(),
            'entryModes' => EntryMode::cases(),
        ]);
    }

    public function show(TradeOpportunity $opportunity, OpportunityPresentationService $presenter): View
    {
        $opportunity->load(['paperPosition', 'events.user:id,name']);

        return view('opportunities.show', [
            'opportunity' => $opportunity,
            'presentation' => $presenter->present($opportunity),
        ]);
    }
}
