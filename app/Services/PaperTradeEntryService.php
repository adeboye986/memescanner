<?php

namespace App\Services;

use App\Chain;
use App\Models\PaperPosition;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class PaperTradeEntryService
{
    public function __construct(
        private TelegramService $telegram,
        private PaperWalletService $wallets,
    ) {}

    /** @param array<string, mixed> $data */
    public function buy(array $data): PaperPosition
    {
        $chain = Chain::fromInput($data['chain'] ?? Chain::Solana->value);
        $address = $chain === Chain::Ethereum
            ? strtolower((string) $data['address'])
            : (string) $data['address'];

        $position = DB::transaction(function () use ($data, $chain, $address): PaperPosition {
            $wallet = $this->wallets->lockedDefault($chain);
            $tradeSize = $this->wallets->tradeSize($chain);
            $currency = $wallet->currencyCode();

            $existing = PaperPosition::query()
                ->where('chain', $chain->value)
                ->where('address', $address)
                ->where('status', 'open')
                ->where('initial_investment_sol', '>', 0)
                ->first();

            if ($existing) {
                return $existing;
            }

            if ((float) $wallet->available_balance_sol < $tradeSize) {
                throw new RuntimeException("Insufficient paper {$currency} balance.");
            }

            $position = PaperPosition::query()->create([
                'chain' => $chain->value,
                'address' => $address,
                'symbol' => $data['symbol'] ?? null,
                'name' => $data['name'] ?? null,
                'discovery_market_cap' => $data['discovery_market_cap'] ?? null,
                'entry_market_cap' => $data['entry_market_cap'],
                'entry_price' => $data['entry_price'] ?? null,
                'entry_liquidity' => $data['entry_liquidity'] ?? null,
                'move_since_discovery_percent' => $data['move_since_discovery_percent'] ?? null,
                'entry_at' => now(),
                'last_market_cap' => $data['entry_market_cap'],
                'last_price' => $data['entry_price'] ?? null,
                'peak_market_cap' => $data['entry_market_cap'],
                'peak_multiple' => 1,
                'max_drawdown_percent' => 0,
                'milestones' => [],
                'meta' => array_merge($data['meta'] ?? [], ['scanner' => $data['scanner'] ?? 'unknown']),
                'status' => 'open',
                'initial_investment_sol' => $tradeSize,
                'remaining_investment_sol' => $tradeSize,
                'realized_sol' => 0,
                'trade_pnl_sol' => 0,
            ]);

            $wallet->available_balance_sol = (float) $wallet->available_balance_sol - $tradeSize;
            $wallet->invested_balance_sol = (float) $wallet->invested_balance_sol + $tradeSize;
            $wallet->save();

            return $position;
        });

        if ($position->wasRecentlyCreated && ($data['send_notification'] ?? false)) {
            $this->sendBuyNotification($position);
        }

        return $position;
    }

    public function sendBuyNotification(PaperPosition $position): void
    {
        $wallet = $this->wallets->default($position->chain);
        $discovery = (float) ($position->discovery_market_cap ?? 0);
        $entry = (float) $position->entry_market_cap;
        $scanner = strtoupper(str_replace(['-', '_'], ' ', (string) data_get($position->meta, 'scanner', 'unknown')));
        $entryMove = $position->move_since_discovery_percent !== null
            ? sprintf('%+.2f%%', (float) $position->move_since_discovery_percent)
            : 'N/A';
        $currency = $wallet->currencyCode();

        try {
            $this->telegram->send(
                "🟢🟢🟢 <b>PAPER BUY EXECUTED</b> 🟢🟢🟢\n\n".
                '<b>Scanner:</b> '.$scanner."\n".
                '<b>Chain:</b> '.strtoupper($position->chain->value)."\n".
                "<b>Symbol:</b> {$position->symbol}\n".
                "<b>Name:</b> {$position->name}\n".
                "<b>Token Address:</b> <code>{$position->address}</code>\n\n".
                '<b>Initial Investment:</b> '.number_format((float) $position->initial_investment_sol, 4)." {$currency}\n".
                '<b>Entry MC:</b> $'.number_format($entry, 2)."\n".
                '<b>Discovery MC:</b> $'.number_format($discovery, 2)."\n".
                '<b>Entry Move:</b> '.$entryMove."\n".
                '<b>Wallet Available:</b> '.number_format((float) $wallet->available_balance_sol, 4)." {$currency}\n".
                '<b>Wallet Invested:</b> '.number_format((float) $wallet->invested_balance_sol, 4)." {$currency}\n\n".
                "<b>EXIT PLAN</b>\nStop Loss: -10% / 0.90x / CLOSE 100%\n+100%: 2.00x / ARM 2.00x FLOOR / HOLD\n+150%: 2.50x / INFORMATIONAL ONLY / HOLD\n+200%: 3.00x / UPGRADE FLOOR TO 3.00x / HOLD\nA full exit occurs only on a later observation at or below the active floor, using the actual observed fill.\n\n<b>NO PARTIAL SELLING</b>\n<b>PAPER TRADE — NO REAL FUNDS USED</b>",
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
