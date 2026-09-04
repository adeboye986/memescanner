<?php

namespace App\Services;

use App\Chain;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserTradingBootstrapService
{
    public function __construct(
        private UserTradingPreferenceService $preferences,
        private PaperStrategyService $strategies,
        private PaperWalletService $wallets,
    ) {}

    public function bootstrap(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $this->preferences->forUser($user);
            $this->strategies->forUser($user);

            foreach (Chain::cases() as $chain) {
                $this->wallets->forUser($user, $chain);
            }
        });
    }
}
