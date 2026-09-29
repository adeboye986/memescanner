<?php

namespace App\Services;

use App\Chain;
use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Enums\TradeOpportunityStatus;
use App\Jobs\SubmitTradingEngineOpportunity;
use App\Models\PaperPosition;
use App\Models\TradeOpportunity;
use App\Models\User;
use App\Services\TradingEngine\TradingEngineOpportunityCommandFactory;
use Throwable;

class TradeOpportunityService
{
    public function __construct(
        private ApplicationSettingsService $settings,
        private EntryPolicy $policy,
        private UserTradingPreferenceService $preferences,
        private UserTelegramNotificationService $userTelegram,
        private TradingEngineOpportunityCommandFactory $tradingEngineCommands,
    ) {}

    /** @param array<string, mixed> $data
     * @return array{opportunity: TradeOpportunity, position: ?PaperPosition}
     */
    public function qualify(array $data, ?User $requestingUser = null): array
    {
        if ($requestingUser) {
            return $this->createForUser($data, $requestingUser);
        }

        $users = User::query()->get();

        if ($users->isEmpty()) {
            return $this->createForUser($data, null);
        }

        $results = collect();
        $firstFailure = null;

        foreach ($users as $user) {
            try {
                $results->push($this->createForUser($data, $user));
            } catch (Throwable $exception) {
                report($exception);
                $firstFailure ??= $exception;
            }
        }

        if ($results->isEmpty() && $firstFailure) {
            throw $firstFailure;
        }

        return $results->first();
    }

    /** @param array<string, mixed> $data
     * @return array{opportunity: TradeOpportunity, position: ?PaperPosition}
     */
    private function createForUser(array $data, ?User $user): array
    {
        $chain = Chain::fromInput($data['chain'] ?? 'solana');
        $scanner = (string) ($data['scanner'] ?? 'unknown');
        $sendNotification = $user !== null && ($data['send_notification'] ?? true);
        $discoveryKey = (string) ($data['discovery_key'] ?? hash('sha256', implode('|', [$chain->value, $scanner, strtolower((string) $data['address']), (string) ($data['discovery_market_cap'] ?? '')])));
        $preference = $user ? $this->preferences->forUser($user) : null;
        $opportunity = TradeOpportunity::query()->firstOrCreate([
            'user_id' => $user?->id,
            'discovery_key' => $discoveryKey,
        ], [
            'chain' => $chain,
            'address' => $data['address'],
            'symbol' => $data['symbol'] ?? null,
            'name' => $data['name'] ?? null,
            'scanner' => $scanner,
            'status' => TradeOpportunityStatus::Qualified,
            'execution_mode' => $preference?->execution_mode ?? ExecutionMode::from((string) $this->settings->get('trading.execution_mode')),
            'entry_mode' => $preference?->entry_mode ?? EntryMode::from((string) $this->settings->get('trading.entry_mode')),
            'pair_address' => data_get($data, 'meta.pair_address'),
            'price' => $data['entry_price'] ?? null,
            'market_cap' => $data['entry_market_cap'] ?? null,
            'liquidity' => $data['entry_liquidity'] ?? null,
            'volume' => $data['volume'] ?? null,
            'qualification_data' => [
                'discovery_market_cap' => $data['discovery_market_cap'] ?? null,
                'move_since_discovery_percent' => $data['move_since_discovery_percent'] ?? null,
                'send_notification' => $sendNotification,
                'meta' => $data['meta'] ?? [],
            ],
            'security_data' => $data['security_data'] ?? null,
            'qualified_at' => now(),
        ]);

        if (! $opportunity->wasRecentlyCreated) {
            return ['opportunity' => $opportunity, 'position' => $opportunity->paperPosition];
        }

        $this->queueTradingEngineExport($opportunity);

        $position = $this->policy->apply($opportunity);

        $status = $opportunity->fresh()->status;

        $shouldNotifyOpportunity = $status === TradeOpportunityStatus::PendingConfirmation
            || ($status === TradeOpportunityStatus::Qualified && $opportunity->entry_mode === EntryMode::Signal);

        if ($sendNotification && ! $position && $shouldNotifyOpportunity) {
            try {
                $instruction = $status === TradeOpportunityStatus::PendingConfirmation
                    ? 'Open Telegram controls or the dashboard to approve or ignore it.'
                    : 'This signal was recorded for your account and will not execute automatically.';
                $this->userTelegram->send(
                    $user,
                    "🔎 <b>TRADE OPPORTUNITY</b>\n\n".
                    '<b>'.htmlspecialchars((string) ($opportunity->symbol ?: 'Unknown'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."</b>\n".
                    'Chain: '.$opportunity->chain->label()."\n".
                    'Mode: '.strtoupper($opportunity->entry_mode->value)."\n\n".
                    $instruction,
                );
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return ['opportunity' => $opportunity, 'position' => $position];
    }

    private function queueTradingEngineExport(TradeOpportunity $opportunity): void
    {
        if (config('services.trading_engine.enabled') !== true
            || config('services.trading_engine.opportunity_export_enabled') !== true
            || $opportunity->user_id === null
            || ! in_array($opportunity->scanner, ['new-token', 'momentum'], true)) {
            return;
        }

        try {
            $command = $this->tradingEngineCommands->make($opportunity);
            SubmitTradingEngineOpportunity::dispatch(
                $command['payload'],
                $command['idempotency_key'],
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
