<?php

namespace App\Console\Commands;

use App\Models\EthereumSwapAttempt;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ethereum:expire-prepared-swaps')]
#[Description('Mark expired prepared Ethereum swap attempts as expired.')]
class ExpirePreparedEthereumSwaps extends Command
{
    public function handle(): int
    {
        $expired = EthereumSwapAttempt::query()
            ->where('status', 'prepared')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update([
                'status' => 'expired',
                'updated_at' => now(),
            ]);

        $this->info("Expired {$expired} prepared Ethereum swap attempt(s).");

        return self::SUCCESS;
    }
}
