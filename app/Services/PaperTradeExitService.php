<?php

namespace App\Services;

use App\Chain;
use App\Models\PaperPosition;
use App\Models\PaperWallet;
use App\Models\User;
use App\Services\Chains\ChainManager;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class PaperTradeExitService
{
    public function __construct(
        private ChainManager $chains,
        private EthereumPaperMarketData $ethereumPaperMarket,
        private PaperMarketObservation $marketObservations,
        private TelegramService $telegram,
        private PaperWalletService $wallets,
        private UserTelegramNotificationService $userTelegram,
    ) {}

    /**
     * @return array{position: PaperPosition, wallet: PaperWallet, event: array<string, mixed>, market_cap: float, multiple: float, price_source: string, fresh_market_error: ?string, notification_error: ?string}
     */
    public function closeManually(PaperPosition $position, ?User $actor = null): array
    {
        $entryMarketCap = (float) $position->entry_market_cap;

        if ($entryMarketCap <= 0) {
            throw new RuntimeException(
                'The position has no valid entry market cap. Position was NOT closed.'
            );
        }

        $marketData = $this->fetchManualCloseObservation($position);
        $this->validatedObservation($position, $marketData);

        $result = DB::transaction(function () use ($position, $marketData, $actor): array {
            $lockedPosition = PaperPosition::query()
                ->whereKey($position->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($actor !== null && ! ($lockedPosition->user_id === $actor->id || ($actor->is_admin && $lockedPosition->user_id === null))) {
                throw new RuntimeException('You are no longer authorized to close this paper position.');
            }

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

            $this->validatedObservation($lockedPosition, $marketData);

            $wallet = $lockedPosition->user_id
                ? $this->wallets->lockedForUser($lockedPosition->user, $lockedPosition->chain)
                : $this->wallets->lockedDefault($lockedPosition->chain);

            $observation = $this->validatedObservation($lockedPosition, $marketData);
            $marketCap = (float) $observation['market_cap'];
            $price = $observation['price_usd'];
            $multiple = (float) $observation['observed_multiple'];

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
                'fill_model' => $observation['fill_model'],
                'execution_verified' => false,
                'estimated_executable_fill' => null,
                'observed_multiple' => $multiple,
                'observed_market_cap' => $marketCap,
                'market_observation' => $observation,
                'proceeds_basis' => 'original_native_asset_cost_times_observed_valuation_ratio',
                'price_source' => 'fresh_market',
                'fresh_market_error' => null,
                'cost_basis_sol' => round($costBasis, 8),
                'sol_returned' => round($solReturned, 8),
                'realized_pnl_sol' => round($realizedPnl, 8),
                'wallet_applied' => true,
                'triggered_at' => now()->toIso8601String(),
            ];

            $exitEvents = $lockedPosition->exit_events ?? [];
            $exitEvents[] = $event;

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
                'meta' => array_replace($lockedPosition->meta ?? [], [
                    'market_observation' => $observation,
                    'last_valid_market_observation' => $observation,
                    'last_valid_market_observation_at' => now()->toIso8601String(),
                ]),
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
                'price_source' => 'fresh_market',
                'fresh_market_error' => null,
            ];
        });

        $result['notification_error'] = $this->sendManualCloseNotification($result);

        return $result;
    }

    /** @return array<string, mixed> */
    private function fetchManualCloseObservation(PaperPosition $position): array
    {
        try {
            if ($position->chain === Chain::Ethereum) {
                $batch = $this->ethereumPaperMarket->fetch([$position]);
                $marketData = $batch['observations'][$position->getKey()] ?? [
                    'available' => false,
                    'reason' => 'provider_observation_missing',
                ];

                if (! ($marketData['available'] ?? false) && ($batch['rate_limited'] ?? false)) {
                    $marketData['reason'] = 'provider_rate_limited';
                }

                return $marketData;
            }

            return [
                ...$this->chains->for($position->chain)->marketData($position->address),
                'provider' => 'dexscreener',
                'fetched_at' => now()->toIso8601String(),
            ];
        } catch (Throwable) {
            return [
                'available' => false,
                'reason' => 'provider_request_failed',
            ];
        }
    }

    /** @return array<string, mixed> */
    private function validatedObservation(PaperPosition $position, array $marketData): array
    {
        $observation = $this->marketObservations->evaluate($position, $marketData);

        if ($observation['simulation_allowed']) {
            return $observation;
        }

        $reasons = array_values(array_unique(array_filter(
            $observation['reasons'],
            fn (mixed $reason): bool => is_string($reason) && $reason !== '',
        )));

        throw new RuntimeException(
            'Guarded PAPER close rejected because the current provider observation is not eligible for simulation: '.
            implode(', ', $reasons !== [] ? $reasons : ['invalid_observation']).'. Position was NOT closed.'
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
                '✖️ <b>Simulated mark (not an executable quote):</b> '.number_format($result['multiple'], 2)."x\n".
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
                $this->userTelegram->send($position->user, $message);
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
