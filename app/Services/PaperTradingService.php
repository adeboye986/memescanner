<?php

namespace App\Services;

use App\Models\PaperPosition;
use App\Models\PaperWallet;
use Illuminate\Support\Facades\DB;

class PaperTradingService
{
    public function buy(array $data): PaperPosition
    {
        return DB::transaction(function () use ($data) {
            $wallet = PaperWallet::query()
                ->where('name', 'default')
                ->lockForUpdate()
                ->firstOrFail();

            $tradeSize = (float) config(
                'services.trading.paper_trade_size_sol',
                0.10
            );

            if ($wallet->available_balance_sol < $tradeSize) {
                throw new \RuntimeException(
                    'Insufficient paper SOL balance.'
                );
            }

            $existing = PaperPosition::query()
                ->where('address', $data['address'])
                ->where('status', 'open')
                ->first();

            if ($existing) {
                return $existing;
            }

            $position = PaperPosition::create([
                'address' => $data['address'],
                'symbol' => $data['symbol'] ?? null,
                'name' => $data['name'] ?? null,

                'discovery_market_cap' =>
                    $data['discovery_market_cap'] ?? null,

                'entry_market_cap' =>
                    $data['entry_market_cap'],

                'entry_price' =>
                    $data['entry_price'] ?? null,

                'entry_liquidity' =>
                    $data['entry_liquidity'] ?? null,

                'move_since_discovery_percent' =>
                    $data['move_since_discovery_percent'] ?? null,

                'entry_at' => now(),

                'last_market_cap' =>
                    $data['entry_market_cap'],

                'last_price' =>
                    $data['entry_price'] ?? null,

                'peak_market_cap' =>
                    $data['entry_market_cap'],

                'peak_multiple' => 1,
                'max_drawdown_percent' => 0,

                'milestones' => [],
                'meta' => $data['meta'] ?? [],

                'status' => 'open',

                'initial_investment_sol' => $tradeSize,
                'remaining_investment_sol' => $tradeSize,
                'realized_sol' => 0,
                'trade_pnl_sol' => 0,
            ]);

            $wallet->available_balance_sol -= $tradeSize;
            $wallet->invested_balance_sol += $tradeSize;

            $wallet->save();

            return $position;
        });
    }
}