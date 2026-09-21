<?php

namespace App\Console\Commands;

use App\Models\SolanaSwapAttempt;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('solana:expire-prepared-swaps')]
#[Description('Mark expired prepared Solana swap attempts as expired.')]
class ExpirePreparedSolanaSwaps extends Command
{
    public function handle(): int
    {
        $expired = SolanaSwapAttempt::query()
            ->where('status', 'prepared')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update([
                'status' => 'expired',
                'updated_at' => now(),
            ]);

        $this->info("Expired {$expired} prepared Solana swap attempt(s).");

        return self::SUCCESS;
    }
}
