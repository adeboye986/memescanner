<?php

namespace App\Console\Commands;

use App\Models\SolanaSwapAttempt;
use App\Services\SolanaOpportunityPreparationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('solana:expire-prepared-swaps')]
#[Description('Mark expired prepared Solana swap attempts as expired.')]
class ExpirePreparedSolanaSwaps extends Command
{
    public function handle(SolanaOpportunityPreparationService $opportunities): int
    {
        $expired = SolanaSwapAttempt::query()
            ->where('status', 'prepared')
            ->whereNull('trade_opportunity_id')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update([
                'status' => 'expired',
                'updated_at' => now(),
            ]);

        $opportunityExpired = $opportunities->cleanup();

        $this->info("Expired {$expired} prepared Solana swap attempt(s).");
        $this->info("Expired {$opportunityExpired} opportunity-linked Solana swap attempt(s).");

        return self::SUCCESS;
    }
}
