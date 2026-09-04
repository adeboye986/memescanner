<?php

namespace App\Services;

use App\Models\PaperPosition;
use App\Models\PaperWallet;
use App\Services\Chains\ChainManager;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class PaperTradeExitService
{
    public function __construct(
        private ChainManager $chains,
        private TelegramService $telegram,
        private PaperWalletService $wallets,
        private TelegramBotManager $telegramBots,
    ) {}

    /**
     * @return array{position: PaperPosition, wallet: PaperWallet, event: array<string, mixed>, market_cap: float, multiple: float, price_source: string, fresh_market_error: ?string, notification_error: ?string}
     */
    public function closeManually(PaperPosition $position): array
    {
        $entryMarketCap = (float) $position->entry_market_cap;

        if ($entryMarketCap <= 0) {
            throw new RuntimeException(
                'No valid fresh, last-known, or entry market-cap data is available. Position was NOT closed.'
            );
        }

        $valuation = $this->resolveManualCloseValuation($position);
        $marketCap = $valuation['market_cap'];
        $price = $valuation['price'];
        $priceSource = $valuation['price_source'];
        $freshMarketError = $valuation['fresh_market_error'];
        $multiple = $marketCap / $entryMarketCap;

        $result = DB::transaction(function () use ($position, $marketCap, $price, $multiple, $priceSource, $freshMarketError): array {
            $lockedPosition = PaperPosition::query()
                ->whereKey($position->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPosition->status !== 'open') {
                throw new RuntimeException('Position is no longer open.');
            }

            if ((float) $lockedPosition->initial_investment_sol <= 0) {
                throw new RuntimeException('Position is not a funded paper trade.');
            }

            $remainingFraction = $lockedPosition->remaining_fraction !== null
                ? (float) $lockedPosition->remaining_fraction
                : 1.0;

            if ($remainingFraction <= 0.000001) {
                throw new RuntimeException('Position has no remaining amount to close.');
            }

            $initialInvestment = (float) $lockedPosition->initial_investment_sol;
            $costBasis = (float) ($lockedPosition->remaining_investment_sol ?? 0);

            if ($costBasis <= 0) {
                $costBasis = $initialInvestment * $remainingFraction;
            }

            $solReturned = $costBasis * $multiple;
            $realizedPnl = $solReturned - $costBasis;
            $realizedValue = (float) ($lockedPosition->realized_value_multiple ?? 0)
                + ($remainingFraction * $multiple);
            $strategyReturnPercent = ($realizedValue - 1) * 100;

            $event = [
                'type' => 'manual_close',
                'label' => 'MANUAL CLOSE',
                'sold_fraction' => $remainingFraction,
                'fill_multiple' => $multiple,
                'observed_multiple' => $multiple,
                'observed_market_cap' => $marketCap,
                'price_source' => $priceSource,
                'fresh_market_error' => $freshMarketError,
                'cost_basis_sol' => round($costBasis, 8),
                'sol_returned' => round($solReturned, 8),
                'realized_pnl_sol' => round($realizedPnl, 8),
                'wallet_applied' => true,
                'triggered_at' => now()->toIso8601String(),
            ];

            $exitEvents = $lockedPosition->exit_events ?? [];
            $exitEvents[] = $event;

            $wallet = $lockedPosition->user_id
                ? $this->wallets->lockedForUser($lockedPosition->user, $lockedPosition->chain)
                : $this->wallets->lockedDefault($lockedPosition->chain);

            $wallet->available_balance_sol = (float) $wallet->available_balance_sol + $solReturned;
            $wallet->invested_balance_sol = max(0.0, (float) $wallet->invested_balance_sol - $costBasis);
            $wallet->realized_pnl_sol = (float) $wallet->realized_pnl_sol + $realizedPnl;
            $wallet->save();

            $peakMarketCap = max(
                (float) ($lockedPosition->peak_market_cap ?? 0),
                (float) $lockedPosition->entry_market_cap,
                $marketCap,
            );

            $lockedPosition->update([
                'last_market_cap' => $marketCap,
                'last_price' => $price,
                'last_checked_at' => now(),
                'peak_market_cap' => $peakMarketCap,
                'peak_multiple' => $peakMarketCap / (float) $lockedPosition->entry_market_cap,
                'remaining_fraction' => 0,
                'remaining_investment_sol' => 0,
                'realized_value_multiple' => $realizedValue,
                'strategy_value_multiple' => $realizedValue,
                'strategy_return_percent' => $strategyReturnPercent,
                'realized_sol' => (float) ($lockedPosition->realized_sol ?? 0) + $solReturned,
                'trade_pnl_sol' => (float) ($lockedPosition->trade_pnl_sol ?? 0) + $realizedPnl,
                'exit_events' => $exitEvents,
                'status' => 'closed',
                'closed_at' => now(),
            ]);

            return [
                'position' => $lockedPosition->fresh(),
                'wallet' => $wallet->fresh(),
                'event' => $event,
                'market_cap' => $marketCap,
                'multiple' => $multiple,
                'price_source' => $priceSource,
                'fresh_market_error' => $freshMarketError,
            ];
        });

        $result['notification_error'] = $this->sendManualCloseNotification($result);

        return $result;
    }

    /**
     * @return array{market_cap: float, price: mixed, price_source: string, fresh_market_error: ?string}
     */
    private function resolveManualCloseValuation(PaperPosition $position): array
    {
        $freshMarketError = null;

        try {
            $marketData = $this->chains->for($position->chain)->marketData($position->address);

            if (! ($marketData['available'] ?? false)) {
                $freshMarketError = 'Current market data is unavailable.';
            } elseif (! ($marketData['requested_token_is_base'] ?? false)) {
                $freshMarketError = 'The current market pair does not identify the requested token as its base token.';
            } elseif ((float) ($marketData['market_cap'] ?? 0) <= 0) {
                $freshMarketError = 'Current market data returned an invalid market cap.';
            } else {
                return [
                    'market_cap' => (float) $marketData['market_cap'],
                    'price' => $marketData['price_usd'] ?? $marketData['price'] ?? null,
                    'price_source' => 'fresh_market',
                    'fresh_market_error' => null,
                ];
            }
        } catch (Throwable $exception) {
            $freshMarketError = 'Could not fetch current market data: '.$exception->getMessage();
        }

        $lastMarketCap = (float) ($position->last_market_cap ?? 0);

        if ($lastMarketCap > 0) {
            return [
                'market_cap' => $lastMarketCap,
                'price' => $position->last_price,
                'price_source' => 'last_known_market',
                'fresh_market_error' => $freshMarketError,
            ];
        }

        $entryMarketCap = (float) ($position->entry_market_cap ?? 0);

        if ($entryMarketCap > 0) {
            return [
                'market_cap' => $entryMarketCap,
                'price' => $position->entry_price,
                'price_source' => 'entry_fallback',
                'fresh_market_error' => $freshMarketError,
            ];
        }

        throw new RuntimeException(
            'No valid fresh, last-known, or entry market-cap data is available. Position was NOT closed.'
        );
    }

    /**
     * @param  array{position: PaperPosition, wallet: PaperWallet, event: array<string, mixed>, market_cap: float, multiple: float, price_source: string, fresh_market_error: ?string}  $result
     */
    private function sendManualCloseNotification(array $result): ?string
    {
        $position = $result['position'];
        $wallet = $result['wallet'];
        $event = $result['event'];
        $currency = $wallet->currencyCode();
        $priceSource = match ($result['price_source']) {
            'last_known_market' => 'Last known market',
            'entry_fallback' => 'Entry fallback',
            default => 'Fresh market',
        };

        try {
            $message = "🛑🛑 <b>PAPER TRADE MANUALLY CLOSED</b> 🛑🛑\n\n".
                "💰 <b>{$position->symbol}</b>\n\n".
                '⛓️ <b>Chain:</b> '.$position->chain->label()."\n".
                "👤 <b>Manual close requested</b>\n".
                "🏷️ <b>Price source:</b> {$priceSource}\n".
                '📊 <b>Close MC:</b> $'.number_format($result['market_cap'], 2)."\n".
                '✖️ <b>Fill:</b> '.number_format($result['multiple'], 2)."x\n".
                '📤 <b>Sold:</b> '.number_format((float) $event['sold_fraction'] * 100, 0)."% of original position\n".
                "🪙 <b>{$currency} Returned:</b> ".number_format((float) $event['sol_returned'], 4)." {$currency}\n".
                '💹 <b>P/L This Exit:</b> '.sprintf('%+.4f %s', (float) $event['realized_pnl_sol'], $currency)."\n".
                '💰 <b>Total Trade P/L:</b> '.sprintf('%+.4f %s', (float) $position->trade_pnl_sol, $currency)."\n".
                '📈 <b>Final Strategy Return:</b> '.sprintf('%+.2f%%', (float) $position->strategy_return_percent)."\n\n".
                "💳 <b>WALLET AFTER CLOSE</b>\n".
                'Available: <b>'.number_format((float) $wallet->available_balance_sol, 4)." {$currency}</b>\n".
                'Invested: <b>'.number_format((float) $wallet->invested_balance_sol, 4)." {$currency}</b>\n".
                'Realized P/L: <b>'.sprintf('%+.4f %s', (float) $wallet->realized_pnl_sol, $currency)."</b>\n\n".
                "❌ <b>POSITION CLOSED</b>\n\n".
                "📍 <code>{$position->address}</code>\n\n".
                "⚠️ <b>PAPER TRADE — NO REAL {$currency} USED</b>";

            if ($position->user_id) {
                $bot = $position->user->telegramBot()->where('enabled', true)->with('identity')->first();
                if ($bot?->identity) {
                    $this->telegramBots->client($bot)->sendMessage($bot->identity->telegram_chat_id, $message);
                }
            } else {
                $this->telegram->send($message);
            }
        } catch (Throwable $exception) {
            report($exception);

            return $exception->getMessage();
        }

        return null;
    }
}
