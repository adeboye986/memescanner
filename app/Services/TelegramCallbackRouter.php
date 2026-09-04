<?php

namespace App\Services;

use App\Chain;
use App\Enums\EntryMode;
use App\Enums\ExecutionMode;
use App\Jobs\RunDashboardCommand;
use App\Models\PaperPosition;
use App\Models\TelegramIdentity;
use App\Models\TradeOpportunity;
use App\Models\User;
use DomainException;
use Throwable;

class TelegramCallbackRouter
{
    public function __construct(
        private TelegramMenuService $menus,
        private SystemActivityService $activities,
        private OpportunityActionService $opportunities,
        private PaperTradeExitService $exits,
        private UserTradingPreferenceService $preferences,
    ) {}

    public function handle(TelegramBotClient $telegram, TelegramIdentity $identity, string $chatId, int $messageId, string $action): void
    {
        try {
            if ($this->showMenu($telegram, $identity, $chatId, $messageId, $action)) {
                return;
            }

            if (preg_match('/^scan_run:(solana|ethereum):(token-scan|momentum-scan)$/', $action, $matches) === 1) {
                $activity = $this->activities->createManual($matches[2], Chain::from($matches[1]), $identity->user);
                RunDashboardCommand::dispatch($activity->id);
                $this->menus->notice($telegram, $chatId, $messageId, '✅ <b>'.$this->escape($activity->label)." queued.</b>\n\nUse System Status to monitor the platform.");

                return;
            }

            if (preg_match('/^opp:(\d+)$/', $action, $matches) === 1) {
                $this->menus->opportunity($telegram, $chatId, $messageId, $this->opportunity((int) $matches[1], $identity->user));

                return;
            }

            if (preg_match('/^(approve|ignore):(\d+)$/', $action, $matches) === 1) {
                $opportunity = $this->opportunity((int) $matches[2], $identity->user);
                if ($matches[1] === 'approve') {
                    $position = $this->opportunities->approve($opportunity, $identity->user);
                    $this->menus->notice($telegram, $chatId, $messageId, "✅ <b>PAPER TRADE OPENED</b>\nPosition #{$position->id}");
                } else {
                    $changed = $this->opportunities->ignore($opportunity, $identity->user);
                    $this->menus->notice($telegram, $chatId, $messageId, $changed ? '🚫 Opportunity ignored.' : 'Opportunity was already ignored.');
                }

                return;
            }

            if (preg_match('/^pos:(\d+)$/', $action, $matches) === 1) {
                $this->menus->position($telegram, $chatId, $messageId, $this->openPosition((int) $matches[1], $identity->user));

                return;
            }

            if (preg_match('/^close:(\d+)$/', $action, $matches) === 1) {
                $this->menus->position($telegram, $chatId, $messageId, $this->openPosition((int) $matches[1], $identity->user), true);

                return;
            }

            if (preg_match('/^close_confirm:(\d+)$/', $action, $matches) === 1) {
                $result = $this->exits->closeManually($this->openPosition((int) $matches[1], $identity->user));
                $source = match ($result['price_source']) {
                    'last_known_market' => ' using its last known market value because fresh data was unavailable',
                    'entry_fallback' => ' using its entry value because no newer market value was available',
                    default => '',
                };
                $this->menus->notice($telegram, $chatId, $messageId, '✅ <b>'.$this->escape($result['position']->symbol).' closed successfully</b>'.$source.'.');

                return;
            }

            if (preg_match('/^setmode:(execution|entry):(paper|live|signal|confirm|auto)$/', $action, $matches) === 1) {
                $this->changeMode($telegram, $identity, $chatId, $messageId, $matches[1], $matches[2]);

                return;
            }

            if (preg_match('/^confirmmode:(execution|entry):(live|auto)$/', $action, $matches) === 1) {
                $current = $this->preferences->forUser($identity->user);
                $execution = $matches[1] === 'execution' ? ExecutionMode::from($matches[2]) : $current->execution_mode;
                $entry = $matches[1] === 'entry' ? EntryMode::from($matches[2]) : $current->entry_mode;
                $this->preferences->update($identity->user, $execution, $entry);
                $this->menus->modes($telegram, $chatId, $messageId, $identity->user);

                return;
            }

            $this->menus->notice($telegram, $chatId, $messageId, 'That action is unavailable. Return to the main menu.');
        } catch (DomainException $exception) {
            $this->menus->notice($telegram, $chatId, $messageId, '⚠️ '.$this->escape($exception->getMessage()));
        } catch (Throwable $exception) {
            report($exception);
            $this->menus->notice($telegram, $chatId, $messageId, '❌ The action could not be completed safely. No unconfirmed action was taken.');
        }
    }

    private function showMenu(TelegramBotClient $telegram, TelegramIdentity $identity, string $chatId, int $messageId, string $action): bool
    {
        match ($action) {
            'menu' => $this->menus->main($telegram, $chatId, $messageId, $identity),
            'scan' => $this->menus->scans($telegram, $chatId, $messageId),
            'opps' => $this->menus->opportunities($telegram, $chatId, $messageId, $identity->user),
            'positions' => $this->menus->positions($telegram, $chatId, $messageId, $identity->user),
            'wallets' => $this->menus->wallets($telegram, $chatId, $messageId, $identity->user),
            'modes' => $this->menus->modes($telegram, $chatId, $messageId, $identity->user),
            'strategy' => $this->menus->strategy($telegram, $chatId, $messageId, $identity->user),
            'status' => $this->menus->status($telegram, $chatId, $messageId, $identity->user),
            default => null,
        };

        return in_array($action, ['menu', 'scan', 'opps', 'positions', 'wallets', 'modes', 'strategy', 'status'], true);
    }

    private function changeMode(TelegramBotClient $telegram, TelegramIdentity $identity, string $chatId, int $messageId, string $group, string $value): void
    {
        if ($group === 'execution' && $value === 'live') {
            $this->menus->notice($telegram, $chatId, $messageId, "⚠️ <b>Live mode warning</b>\n\nLive execution is not implemented and remains blocked server-side. Confirm only if you intend to change the configured mode.", [[['text' => 'Cancel', 'callback_data' => 'modes'], ['text' => 'Confirm LIVE', 'callback_data' => 'confirmmode:execution:live']]]);

            return;
        }

        if ($group === 'entry' && $value === 'auto' && $this->preferences->forUser($identity->user)->execution_mode === ExecutionMode::Live) {
            $this->menus->notice($telegram, $chatId, $messageId, "⚠️ <b>Auto + LIVE warning</b>\n\nReal transactions remain blocked, but this changes the configured entry policy.", [[['text' => 'Cancel', 'callback_data' => 'modes'], ['text' => 'Confirm AUTO', 'callback_data' => 'confirmmode:entry:auto']]]);

            return;
        }

        $current = $this->preferences->forUser($identity->user);
        $execution = $group === 'execution' ? ExecutionMode::from($value) : $current->execution_mode;
        $entry = $group === 'entry' ? EntryMode::from($value) : $current->entry_mode;
        $this->preferences->update($identity->user, $execution, $entry);
        $this->menus->modes($telegram, $chatId, $messageId, $identity->user);
    }

    private function openPosition(int $id, User $user): PaperPosition
    {
        $position = PaperPosition::query()->whereKey($id)->where(function ($query) use ($user): void {
            $query->where('user_id', $user->id);
            if ($user->is_admin) {
                $query->orWhereNull('user_id');
            }
        })->firstOrFail();

        if ($position->status !== 'open' || (float) $position->initial_investment_sol <= 0 || (float) $position->remaining_fraction <= 0) {
            throw new DomainException('This funded position is no longer open.');
        }

        return $position;
    }

    private function opportunity(int $id, User $user): TradeOpportunity
    {
        return TradeOpportunity::query()->whereKey($id)->where(function ($query) use ($user): void {
            $query->where('user_id', $user->id);
            if ($user->is_admin) {
                $query->orWhereNull('user_id');
            }
        })->firstOrFail();
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
