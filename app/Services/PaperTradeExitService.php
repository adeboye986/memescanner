<?php

namespace App\Services;

use App\Models\PaperPosition;
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
    ) {}

    /**
     * @return array{position: PaperPosition, wallet: PaperWallet, event: array<string, mixed>, market_cap: float, multiple: float, notification_error: ?string}
     */
    public function closeManually(PaperPosition $position): array
    {
        try {
            $dex = $this->chains->for($position->chain)->marketData($position->address);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Could not fetch current Dex price: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        if (! ($dex['available'] ?? false)) {
            throw new RuntimeException('Current Dex pair is unavailable. Position was NOT closed.');
        }

        if (! ($dex['requested_token_is_base'] ?? false)) {
            throw new RuntimeException('Dex pair does not identify the requested token as the base token. Position was NOT closed.');
        }

        $marketCap = (float) ($dex['market_cap'] ?? 0);
        $price = $dex['price_usd'] ?? $dex['price'] ?? null;
        $entryMarketCap = (float) $position->entry_market_cap;

        if ($marketCap <= 0 || $entryMarketCap <= 0) {
            throw new RuntimeException('Invalid market-cap data. Position was NOT closed.');
        }

        $multiple = $marketCap / $entryMarketCap;

        $result = DB::transaction(function () use ($position, $marketCap, $price, $multiple): array {
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
                'cost_basis_sol' => round($costBasis, 8),
                'sol_returned' => round($solReturned, 8),
                'realized_pnl_sol' => round($realizedPnl, 8),
                'wallet_applied' => true,
                'triggered_at' => now()->toIso8601String(),
            ];

            $exitEvents = $lockedPosition->exit_events ?? [];
            $exitEvents[] = $event;

            $wallet = $this->wallets->lockedDefault($lockedPosition->chain);

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
            ];
        });

        $result['notification_error'] = $this->sendManualCloseNotification($result);

        return $result;
    }

    /**
     * @param  array{position: PaperPosition, wallet: PaperWallet, event: array<string, mixed>, market_cap: float, multiple: float}  $result
     */
    private function sendManualCloseNotification(array $result): ?string
    {
        $position = $result['position'];
        $wallet = $result['wallet'];
        $event = $result['event'];
        $currency = $wallet->currencyCode();

        try {
            $this->telegram->send(
                "🛑🛑 <b>PAPER TRADE MANUALLY CLOSED</b> 🛑🛑\n\n".
                "💰 <b>{$position->symbol}</b>\n\n".
                '⛓️ <b>Chain:</b> '.$position->chain->label()."\n".
                "👤 <b>Manual close requested</b>\n".
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
                "⚠️ <b>PAPER TRADE — NO REAL {$currency} USED</b>",
            );
        } catch (Throwable $exception) {
            report($exception);

            return $exception->getMessage();
        }

        return null;
    }
}
