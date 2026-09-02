<?php

namespace App\Services;

use App\Chain;
use App\Jobs\RunDashboardCommand;
use App\Models\PaperPosition;
use App\Models\TelegramIdentity;
use App\Models\TradeOpportunity;
use DomainException;
use Throwable;

class TelegramCallbackRouter
{
    public function __construct(
        private TelegramMenuService $menus,
        private SystemActivityService $activities,
        private OpportunityActionService $opportunities,
        private PaperTradeExitService $exits,
        private ApplicationSettingsService $settings,
    ) {}

    public function handle(TelegramIdentity $identity, string $chatId, int $messageId, string $action): void
    {
        try {
            if ($this->showMenu($identity, $chatId, $messageId, $action)) {
                return;
            }

            if (preg_match('/^scan_run:(solana|ethereum):(token-scan|momentum-scan)$/', $action, $matches) === 1) {
                $activity = $this->activities->createManual($matches[2], Chain::from($matches[1]));
                RunDashboardCommand::dispatch($activity->id);
                $this->menus->notice($chatId, $messageId, '✅ <b>'.$this->escape($activity->label)." queued.</b>\n\nUse System Status to monitor the platform.");

                return;
            }

            if (preg_match('/^opp:(\d+)$/', $action, $matches) === 1) {
                $this->menus->opportunity($chatId, $messageId, TradeOpportunity::query()->findOrFail($matches[1]));

                return;
            }

            if (preg_match('/^(approve|ignore):(\d+)$/', $action, $matches) === 1) {
                $opportunity = TradeOpportunity::query()->findOrFail($matches[2]);
                if ($matches[1] === 'approve') {
                    $position = $this->opportunities->approve($opportunity, $identity->user);
                    $this->menus->notice($chatId, $messageId, "✅ <b>PAPER TRADE OPENED</b>\nPosition #{$position->id}");
                } else {
                    $changed = $this->opportunities->ignore($opportunity, $identity->user);
                    $this->menus->notice($chatId, $messageId, $changed ? '🚫 Opportunity ignored.' : 'Opportunity was already ignored.');
                }

                return;
            }

            if (preg_match('/^pos:(\d+)$/', $action, $matches) === 1) {
                $this->menus->position($chatId, $messageId, $this->openPosition((int) $matches[1]));

                return;
            }

            if (preg_match('/^close:(\d+)$/', $action, $matches) === 1) {
                $this->menus->position($chatId, $messageId, $this->openPosition((int) $matches[1]), true);

                return;
            }

            if (preg_match('/^close_confirm:(\d+)$/', $action, $matches) === 1) {
                $result = $this->exits->closeManually($this->openPosition((int) $matches[1]));
                $source = match ($result['price_source']) {
                    'last_known_market' => ' using its last known market value because fresh data was unavailable',
                    'entry_fallback' => ' using its entry value because no newer market value was available',
                    default => '',
                };
                $this->menus->notice($chatId, $messageId, '✅ <b>'.$this->escape($result['position']->symbol).' closed successfully</b>'.$source.'.');

                return;
            }

            if (preg_match('/^setmode:(execution|entry):(paper|live|signal|confirm|auto)$/', $action, $matches) === 1) {
                $this->changeMode($identity, $chatId, $messageId, $matches[1], $matches[2]);

                return;
            }

            if (preg_match('/^confirmmode:(execution|entry):(live|auto)$/', $action, $matches) === 1) {
                $key = 'trading.'.$matches[1].'_mode';
                $this->settings->update([$key => $matches[2]], $identity->user);
                $this->menus->modes($chatId, $messageId);

                return;
            }

            $this->menus->notice($chatId, $messageId, 'That action is unavailable. Return to the main menu.');
        } catch (DomainException $exception) {
            $this->menus->notice($chatId, $messageId, '⚠️ '.$this->escape($exception->getMessage()));
        } catch (Throwable $exception) {
            report($exception);
            $this->menus->notice($chatId, $messageId, '❌ The action could not be completed safely. No unconfirmed action was taken.');
        }
    }

    private function showMenu(TelegramIdentity $identity, string $chatId, int $messageId, string $action): bool
    {
        match ($action) {
            'menu' => $this->menus->main($chatId, $messageId, $identity),
            'scan' => $this->menus->scans($chatId, $messageId),
            'opps' => $this->menus->opportunities($chatId, $messageId),
            'positions' => $this->menus->positions($chatId, $messageId),
            'wallets' => $this->menus->wallets($chatId, $messageId),
            'modes' => $this->menus->modes($chatId, $messageId),
            'strategy' => $this->menus->strategy($chatId, $messageId),
            'status' => $this->menus->status($chatId, $messageId),
            default => null,
        };

        return in_array($action, ['menu', 'scan', 'opps', 'positions', 'wallets', 'modes', 'strategy', 'status'], true);
    }

    private function changeMode(TelegramIdentity $identity, string $chatId, int $messageId, string $group, string $value): void
    {
        if ($group === 'execution' && $value === 'live') {
            $this->menus->notice($chatId, $messageId, "⚠️ <b>Live mode warning</b>\n\nLive execution is not implemented and remains blocked server-side. Confirm only if you intend to change the configured mode.", [[['text' => 'Cancel', 'callback_data' => 'modes'], ['text' => 'Confirm LIVE', 'callback_data' => 'confirmmode:execution:live']]]);

            return;
        }

        if ($group === 'entry' && $value === 'auto' && $this->settings->get('trading.execution_mode') === 'live') {
            $this->menus->notice($chatId, $messageId, "⚠️ <b>Auto + LIVE warning</b>\n\nReal transactions remain blocked, but this changes the configured entry policy.", [[['text' => 'Cancel', 'callback_data' => 'modes'], ['text' => 'Confirm AUTO', 'callback_data' => 'confirmmode:entry:auto']]]);

            return;
        }

        $this->settings->update(['trading.'.$group.'_mode' => $value], $identity->user);
        $this->menus->modes($chatId, $messageId);
    }

    private function openPosition(int $id): PaperPosition
    {
        $position = PaperPosition::query()->findOrFail($id);

        if ($position->status !== 'open' || (float) $position->initial_investment_sol <= 0 || (float) $position->remaining_fraction <= 0) {
            throw new DomainException('This funded position is no longer open.');
        }

        return $position;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
