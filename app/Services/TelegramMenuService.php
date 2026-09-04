<?php

namespace App\Services;

use App\Chain;
use App\Enums\ExecutionMode;
use App\Enums\TradeOpportunityStatus;
use App\Models\PaperPosition;
use App\Models\TelegramIdentity;
use App\Models\TradeOpportunity;
use App\Models\User;

class TelegramMenuService
{
    public const MAIN_KEYBOARD = [
        [['text' => '🔎 Scans', 'callback_data' => 'scan'], ['text' => '🎯 Opportunities', 'callback_data' => 'opps']],
        [['text' => '📈 Positions', 'callback_data' => 'positions'], ['text' => '💰 Wallets', 'callback_data' => 'wallets']],
        [['text' => '⚙️ Modes', 'callback_data' => 'modes'], ['text' => '🛡 Strategy', 'callback_data' => 'strategy']],
        [['text' => '🟢 System Status', 'callback_data' => 'status']],
    ];

    public function __construct(
        private ApplicationSettingsService $settings,
        private PaperWalletService $wallets,
        private PaperStrategyService $strategies,
        private IntegrationStatusService $integrations,
        private UserTradingPreferenceService $preferences,
        private OperationalHealthService $operationalHealth,
    ) {}

    public function main(TelegramBotClient $telegram, string $chatId, ?int $messageId = null, ?TelegramIdentity $identity = null): void
    {
        $name = $this->escape((string) $this->settings->get('general.application_name'));
        $this->respond($telegram, $chatId, $messageId, "<b>{$name} Trading Console</b>\n\nChoose an operation. All privileged actions are verified against your linked account.", self::MAIN_KEYBOARD);
    }

    public function scans(TelegramBotClient $telegram, string $chatId, int $messageId): void
    {
        $keyboard = [];
        foreach (Chain::cases() as $chain) {
            $keyboard[] = [['text' => '🔎 '.$chain->label().' Token Scan', 'callback_data' => "scan_run:{$chain->value}:token-scan"]];
            $keyboard[] = [['text' => '⚡ '.$chain->label().' Momentum', 'callback_data' => "scan_run:{$chain->value}:momentum-scan"]];
        }
        $keyboard[] = $this->back();
        $this->respond($telegram, $chatId, $messageId, "<b>Scanner Controls</b>\n\nScans run through the existing queue-backed command system.", $keyboard);
    }

    public function opportunities(TelegramBotClient $telegram, string $chatId, int $messageId, User $user): void
    {
        $items = TradeOpportunity::query()->where(function ($query) use ($user): void {
            $query->where('user_id', $user->id);
            if ($user->is_admin) {
                $query->orWhereNull('user_id');
            }
        })->latest()->limit(5)->get();
        $lines = ['<b>Recent Trade Opportunities</b>', ''];
        $keyboard = [];
        foreach ($items as $item) {
            $lines[] = '#'.$item->id.' '.$this->escape($item->symbol).' · '.$item->chain->label().' · '.str($item->status->value)->headline();
            $keyboard[] = [['text' => '#'.$item->id.' '.$item->symbol, 'callback_data' => 'opp:'.$item->id]];
        }
        if ($items->isEmpty()) {
            $lines[] = 'No opportunities have been recorded.';
        }
        $keyboard[] = $this->back();
        $this->respond($telegram, $chatId, $messageId, implode("\n", $lines), $keyboard);
    }

    public function opportunity(TelegramBotClient $telegram, string $chatId, int $messageId, TradeOpportunity $item): void
    {
        $text = "<b>{$this->escape($item->symbol)} · {$item->chain->label()}</b>\nStatus: ".str($item->status->value)->headline()."\nScanner: {$this->escape((string) $item->scanner)}\nMarket cap: ".$this->money($item->market_cap)."\nLiquidity: ".$this->money($item->liquidity)."\nVolume: ".$this->money($item->volume)."\nQualified: ".($item->qualified_at?->diffForHumans() ?? 'Unknown')."\nAddress: <code>{$this->escape($item->address)}</code>";
        $keyboard = [];
        if ($item->status === TradeOpportunityStatus::PendingConfirmation) {
            $keyboard[] = [['text' => '✅ Approve', 'callback_data' => 'approve:'.$item->id], ['text' => '🚫 Ignore', 'callback_data' => 'ignore:'.$item->id]];
        }
        if ($item->paper_position_id) {
            $keyboard[] = [['text' => '📈 Position', 'callback_data' => 'pos:'.$item->paper_position_id]];
        }
        $keyboard[] = [['text' => 'Details', 'url' => route('opportunities.show', $item)]];
        $keyboard[] = [['text' => '‹ Opportunities', 'callback_data' => 'opps']];
        $this->respond($telegram, $chatId, $messageId, $text, $keyboard);
    }

    public function positions(TelegramBotClient $telegram, string $chatId, int $messageId, User $user): void
    {
        $positions = PaperPosition::query()->where(function ($query) use ($user): void {
            $query->where('user_id', $user->id);
            if ($user->is_admin) {
                $query->orWhereNull('user_id');
            }
        })->where('status', 'open')->where('initial_investment_sol', '>', 0)->latest()->limit(5)->get();
        $lines = ['<b>Open Paper Positions</b>', ''];
        $keyboard = [];
        foreach ($positions as $position) {
            $multiple = $position->entry_market_cap > 0 ? ($position->last_market_cap ?: $position->entry_market_cap) / $position->entry_market_cap : 0;
            $lines[] = '#'.$position->id.' '.$this->escape($position->symbol).' · '.number_format($multiple, 2).'x';
            $keyboard[] = [['text' => '#'.$position->id.' '.$position->symbol, 'callback_data' => 'pos:'.$position->id]];
        }
        if ($positions->isEmpty()) {
            $lines[] = 'No funded open positions.';
        }
        $keyboard[] = $this->back();
        $this->respond($telegram, $chatId, $messageId, implode("\n", $lines), $keyboard);
    }

    public function position(TelegramBotClient $telegram, string $chatId, int $messageId, PaperPosition $position, bool $confirm = false): void
    {
        $marketCap = $position->last_market_cap ?: $position->entry_market_cap;
        $multiple = $position->entry_market_cap > 0 ? $marketCap / $position->entry_market_cap : 0;
        $strategy = $this->strategies->forPosition($position);
        $currency = $this->wallets->currency($position->chain);
        $value = (float) ($position->remaining_investment_sol ?: $position->initial_investment_sol) * $multiple;
        $warning = $confirm ? "\n\n⚠️ <b>This closes 100% of the remaining paper position.</b>" : '';
        $text = "<b>{$this->escape($position->symbol)} · Position #{$position->id}</b>\n{$position->chain->label()} · {$this->escape($position->status)}\nReturn: ".sprintf('%+.2f%%', ($multiple - 1) * 100)."\nEstimated value: ".number_format($value, 4)." {$currency}\nRemaining: ".number_format((float) $position->remaining_fraction * 100, 0)."%\nStrategy: SL -{$strategy['stop_loss_percent']}% · P1 +{$strategy['protection_level_1_percent']}% · P2 +{$strategy['protection_level_2_percent']}%{$warning}";
        $keyboard = $confirm
            ? [[['text' => 'Cancel', 'callback_data' => 'pos:'.$position->id], ['text' => '🛑 Close Position', 'callback_data' => 'close_confirm:'.$position->id]]]
            : [[['text' => '🔄 Refresh', 'callback_data' => 'pos:'.$position->id], ['text' => 'Close Trade', 'callback_data' => 'close:'.$position->id]]];
        $keyboard[] = [['text' => '‹ Positions', 'callback_data' => 'positions']];
        $this->respond($telegram, $chatId, $messageId, $text, $keyboard);
    }

    public function wallets(TelegramBotClient $telegram, string $chatId, int $messageId, User $user): void
    {
        $lines = ['<b>Paper Wallets</b>', ''];
        foreach (Chain::cases() as $chain) {
            $wallet = $this->wallets->forUser($user, $chain);
            $currency = $wallet->currencyCode();
            $lines[] = "<b>{$chain->label()}</b>\nAvailable: ".number_format((float) $wallet->available_balance_sol, 4)." {$currency}\nInvested: ".number_format((float) $wallet->invested_balance_sol, 4)." {$currency}\nRealized P/L: ".sprintf('%+.4f', (float) $wallet->realized_pnl_sol)." {$currency}\n";
        }
        if ($this->preferences->forUser($user)->execution_mode === ExecutionMode::Live) {
            $lines[] = '⚠️ Live wallet execution is not enabled yet.';
        }
        $this->respond($telegram, $chatId, $messageId, implode("\n", $lines), [$this->back()]);
    }

    public function modes(TelegramBotClient $telegram, string $chatId, int $messageId, User $user): void
    {
        $preference = $this->preferences->forUser($user);
        $execution = strtoupper($preference->execution_mode->value);
        $entry = strtoupper($preference->entry_mode->value);
        $keyboard = [
            [['text' => 'Paper', 'callback_data' => 'setmode:execution:paper'], ['text' => 'Live ⚠️', 'callback_data' => 'setmode:execution:live']],
            [['text' => 'Signal', 'callback_data' => 'setmode:entry:signal'], ['text' => 'Confirm', 'callback_data' => 'setmode:entry:confirm'], ['text' => 'Auto', 'callback_data' => 'setmode:entry:auto']],
            $this->back(),
        ];
        $this->respond($telegram, $chatId, $messageId, "<b>Trading Modes</b>\n\nExecution: {$execution}\nEntry policy: {$entry}\n\nLive execution remains server-side blocked.", $keyboard);
    }

    public function strategy(TelegramBotClient $telegram, string $chatId, int $messageId, User $user): void
    {
        $strategy = $this->strategies->forUser($user);
        $url = route('settings.index');
        $keyboard = [[['text' => 'Edit in Web Settings', 'url' => $url]], $this->back()];
        $this->respond($telegram, $chatId, $messageId, "<b>Paper Strategy Defaults</b>\n\nStop loss: -{$strategy['stop_loss_percent']}%\nProtection 1: +{$strategy['protection_level_1_percent']}%\nProtection 2: +{$strategy['protection_level_2_percent']}%\n\nChanges apply only to new positions.", $keyboard);
    }

    public function status(TelegramBotClient $telegram, string $chatId, int $messageId, User $user): void
    {
        $preference = $this->preferences->forUser($user);
        $lines = [
            '<b>System Status</b>',
            '',
            'Execution: '.strtoupper($preference->execution_mode->value),
            'Entry: '.strtoupper($preference->entry_mode->value),
            'Open positions: '.PaperPosition::query()->where('user_id', $user->id)->where('status', 'open')->count(),
            'Pending opportunities: '.TradeOpportunity::query()->where('user_id', $user->id)->where('status', 'pending_confirmation')->count(),
            'Kill switch: '.($this->settings->get('risk.kill_switch') ? 'ACTIVE' : 'Off'),
            '',
        ];
        $operations = $this->operationalHealth->status();
        $lines[] = 'Platform: Online';
        $lines[] = 'Telegram Bot: Connected';
        $lines[] = 'Tracker: '.str($operations['fast_tracker']['status'])->replace('_', ' ')->headline();
        if ($user->is_admin) {
            $lines[] = '';
            $lines[] = '<b>Admin Operations</b>';
            $lines[] = 'Scheduler: '.str($operations['scheduler']['status'])->replace('_', ' ')->headline();
            $lines[] = 'Queue: '.str($operations['queue']['status'])->replace('_', ' ')->headline();
            $lines[] = 'Failed jobs: '.$operations['failed_jobs'];
            $lines[] = 'Pending jobs: '.$operations['pending_jobs'];
        }
        $this->respond($telegram, $chatId, $messageId, implode("\n", $lines), [$this->back()]);
    }

    /** @param array<int, array<int, array<string, string>>> $keyboard */
    public function notice(TelegramBotClient $telegram, string $chatId, int $messageId, string $message, array $keyboard = []): void
    {
        $this->respond($telegram, $chatId, $messageId, $message, $keyboard === [] ? [self::MAIN_KEYBOARD[0], $this->back()] : $keyboard);
    }

    /** @param array<int, array<int, array<string, string>>> $keyboard */
    private function respond(TelegramBotClient $telegram, string $chatId, ?int $messageId, string $text, array $keyboard): void
    {
        if ($messageId) {
            $telegram->editMessageText($chatId, $messageId, $text, $keyboard);
        } else {
            $telegram->sendMessage($chatId, $text, $keyboard);
        }
    }

    /** @return array<int, array<string, string>> */
    private function back(): array
    {
        return [['text' => '‹ Main Menu', 'callback_data' => 'menu']];
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function money(mixed $value): string
    {
        return (float) $value > 0 ? '$'.number_format((float) $value, 2) : 'N/A';
    }
}
