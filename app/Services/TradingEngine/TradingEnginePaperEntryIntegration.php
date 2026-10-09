<?php

namespace App\Services\TradingEngine;

use App\Chain;
use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Jobs\SubmitTradingEnginePaperEntry;
use App\Models\PaperPosition;
use App\Models\TradeOpportunity;
use App\Models\TradingEngineOpportunityEvaluation;
use App\Models\TradingEngineOpportunityLink;
use App\Models\TradingEnginePaperEntryIntent;
use Illuminate\Support\Facades\DB;
use LogicException;

class TradingEnginePaperEntryIntegration
{
    public function __construct(
        private TradingEnginePaperEntryCommandFactory $commands,
        private TradingEngineCanonicalJson $canonicalJson,
    ) {}

    public function routes(TradeOpportunity $opportunity): bool
    {
        return config('services.trading_engine.enabled', false) === true
            && config('services.trading_engine.paper_entry_integration_enabled', false) === true
            && $opportunity->chain === Chain::Solana
            && $opportunity->execution_mode === ExecutionMode::Paper
            && $opportunity->entry_mode === EntryMode::Auto
            && $opportunity->user_id !== null
            && in_array($opportunity->user_id, $this->canaryUserIds(), true);
    }

    public function submit(
        TradeOpportunity $opportunity,
        TradingEngineOpportunityLink $link,
        TradingEngineOpportunityEvaluation $evaluation,
    ): ?TradingEnginePaperEntryIntent {
        if (! $this->routes($opportunity)) {
            throw new LogicException('The opportunity is outside the engine PAPER entry cutover boundary.');
        }

        $existing = TradingEnginePaperEntryIntent::query()
            ->where('trade_opportunity_id', $opportunity->getKey())
            ->first();

        if ($existing instanceof TradingEnginePaperEntryIntent) {
            if ($existing->opportunity_link_id !== $link->getKey()
                || $existing->opportunity_evaluation_id !== $evaluation->getKey()
                || $existing->user_id !== $opportunity->user_id
                || $existing->idempotency_key !== 'paper:entry:laravel:'.$opportunity->getKey().':v1'
                || ! is_array($existing->payload)
                || $this->canonicalJson->hash($existing->payload) !== $existing->payload_sha256) {
                throw new LogicException('The existing engine PAPER entry intent failed integrity validation.');
            }

            if ($existing->status === 'pending') {
                DB::afterCommit(fn () => SubmitTradingEnginePaperEntry::dispatch($existing->getKey()));
            }

            return $existing;
        }

        if ($this->hasOpenLegacyPosition($opportunity)) {
            return null;
        }

        $command = $this->commands->make($opportunity, $link, $evaluation);
        $intent = TradingEnginePaperEntryIntent::query()->create([
            'trade_opportunity_id' => $opportunity->getKey(),
            'opportunity_link_id' => $link->getKey(),
            'opportunity_evaluation_id' => $evaluation->getKey(),
            'user_id' => $opportunity->user_id,
            'idempotency_key' => $command['idempotency_key'],
            'payload_sha256' => $this->canonicalJson->hash($command['payload']),
            'payload' => $command['payload'],
            'status' => 'pending',
        ]);

        DB::afterCommit(fn () => SubmitTradingEnginePaperEntry::dispatch($intent->getKey()));

        return $intent;
    }

    private function hasOpenLegacyPosition(TradeOpportunity $opportunity): bool
    {
        return PaperPosition::query()
            ->where('user_id', $opportunity->user_id)
            ->where('chain', $opportunity->chain->value)
            ->where('address', $opportunity->address)
            ->where('status', 'open')
            ->exists();
    }

    /** @return list<int> */
    private function canaryUserIds(): array
    {
        $configured = config('services.trading_engine.paper_entry_canary_user_ids', '');

        if (! is_string($configured)) {
            return [];
        }

        return array_values(array_unique(array_map(
            fn (string $id): int => (int) $id,
            array_filter(
                array_map('trim', explode(',', $configured)),
                fn (string $id): bool => preg_match('/^[1-9][0-9]*$/D', $id) === 1,
            ),
        )));
    }
}
