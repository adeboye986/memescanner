<?php

namespace App\Http\Controllers;

use App\Chain;
use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Enums\TradeOpportunityStatus;
use App\Http\Requests\OpportunityIndexRequest;
use App\Models\TradeOpportunity;
use App\Services\EthereumOpportunityExecutionPolicy;
use App\Services\OpportunityPresentationService;
use App\Services\SolanaOpportunityExecutionPolicy;
use App\Services\TradingEngine\TradingEngineOpportunityLifecyclePresenter;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

class OpportunityController extends Controller
{
    public function index(
        OpportunityIndexRequest $request,
        OpportunityPresentationService $presenter,
    ): View {
        $filters = $request->validated();
        $user = $request->user();
        $tab = $this->resolveTab($filters);
        $tabStatuses = $this->statusesForTab($tab);
        $statusValues = array_map(
            static fn (TradeOpportunityStatus $status): string => $status->value,
            $tabStatuses,
        );
        $search = trim((string) ($filters['search'] ?? ''));
        $filters = [
            ...$filters,
            'tab' => $tab,
            'search' => $search,
        ];

        $opportunities = TradeOpportunity::query()
            ->where(function (Builder $query) use ($user): void {
                $query->where('user_id', $user->id);
                if ($user->is_admin) {
                    $query->orWhereNull('user_id');
                }
            })
            ->with('engineOpportunityLink:id,trade_opportunity_id,engine_opportunity_id')
            ->whereIn('status', $statusValues)
            ->when($search !== '', function (Builder $query) use ($search): void {
                $pattern = '%'.$search.'%';
                $query->where(function (Builder $query) use ($pattern): void {
                    $query
                        ->whereLike('symbol', $pattern)
                        ->orWhereLike('name', $pattern)
                        ->orWhereLike('address', $pattern);
                });
            })
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['chain'] ?? null, fn ($query, string $chain) => $query->where('chain', $chain))
            ->when($filters['entry_mode'] ?? null, fn ($query, string $mode) => $query->where('entry_mode', $mode))
            ->when($filters['score_band'] ?? null, function (Builder $query, string $scoreBand): void {
                match ($scoreBand) {
                    'strong' => $query->where('security_data->score', '>=', 80),
                    'moderate' => $query->whereBetween('security_data->score', [60, 79]),
                    'elevated' => $query->where('security_data->score', '<', 60),
                };
            })
            ->orderByDesc('qualified_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (TradeOpportunity $opportunity): array => $presenter->presentIndex($opportunity));

        return view('opportunities.index', [
            'opportunities' => $opportunities,
            'filters' => $filters,
            'tabs' => [
                'active' => 'Active Opportunities',
                'ignored' => 'Ignored',
                'history' => 'History',
            ],
            'statusOptions' => $tabStatuses,
            'chains' => Chain::cases(),
            'entryModes' => EntryMode::cases(),
            'scoreBands' => [
                'strong' => 'Score 80–100',
                'moderate' => 'Score 60–79',
                'elevated' => 'Score below 60',
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function resolveTab(array $filters): string
    {
        if (isset($filters['tab'])) {
            return $filters['tab'];
        }

        return match ($filters['status'] ?? null) {
            TradeOpportunityStatus::Ignored->value => 'ignored',
            TradeOpportunityStatus::Executed->value,
            TradeOpportunityStatus::Expired->value,
            TradeOpportunityStatus::Failed->value => 'history',
            default => 'active',
        };
    }

    /**
     * @return list<TradeOpportunityStatus>
     */
    private function statusesForTab(string $tab): array
    {
        return match ($tab) {
            'ignored' => [TradeOpportunityStatus::Ignored],
            'history' => [
                TradeOpportunityStatus::Executed,
                TradeOpportunityStatus::Expired,
                TradeOpportunityStatus::Failed,
            ],
            default => [
                TradeOpportunityStatus::Qualified,
                TradeOpportunityStatus::PendingConfirmation,
                TradeOpportunityStatus::Executing,
            ],
        };
    }

    public function show(
        TradeOpportunity $opportunity,
        OpportunityPresentationService $presenter,
        EthereumOpportunityExecutionPolicy $ethereumPolicy,
        SolanaOpportunityExecutionPolicy $solanaPolicy,
        TradingEngineOpportunityLifecyclePresenter $lifecyclePresenter,
    ): View {
        $user = request()->user();
        abort_unless($opportunity->user_id === $user->id || ($user->is_admin && $opportunity->user_id === null), 404);
        $opportunity->load(['paperPosition', 'events.user:id,name', 'engineOpportunityLink', 'engineOpportunityExport', 'engineOpportunityEvaluations', 'systemActivities', 'enginePaperEntryIntent', 'enginePaperPosition']);
        $preference = $user->tradingPreference;
        $ethereumLive = $opportunity->user_id === $user->id && $opportunity->chain === Chain::Ethereum
            && (($opportunity->entry_mode === EntryMode::Confirm
                && ($opportunity->execution_mode === ExecutionMode::Live
                    || ($opportunity->status === TradeOpportunityStatus::PendingConfirmation
                        && $preference?->execution_mode === ExecutionMode::Live && $preference?->entry_mode === EntryMode::Confirm)))
                || $ethereumPolicy->permitsConfirmFirstFlow($opportunity));
        $solanaLive = $opportunity->user_id === $user->id
            && $opportunity->chain === Chain::Solana
            && $solanaPolicy->permitsConfirmFirstFlow($opportunity);

        if ($ethereumLive) {
            $opportunity->load('ethereumSwapAttempt');
        }
        if ($solanaLive) {
            $opportunity->load('solanaSwapAttempt');
        }

        return view('opportunities.show', [
            'opportunity' => $opportunity,
            'presentation' => $presenter->present($opportunity),
            'engineLifecycle' => $lifecyclePresenter->present($opportunity),
            'ethereumLive' => $ethereumLive,
            'solanaLive' => $solanaLive,
        ]);
    }
}
