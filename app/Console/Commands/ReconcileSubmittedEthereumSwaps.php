<?php

namespace App\Console\Commands;

use App\Models\EthereumSwapAttempt;
use App\Services\EthereumReceiptReconciliationService;
use App\Services\EthereumService;
use DomainException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('ethereum:reconcile-submitted-swaps')]
#[Description('Check submitted Ethereum swaps and mark mined transactions as confirmed or failed.')]
class ReconcileSubmittedEthereumSwaps extends Command
{
    public function handle(EthereumService $ethereum, EthereumReceiptReconciliationService $reconciliation): int
    {
        $checked = 0;
        $confirmed = 0;
        $failed = 0;
        $rejected = 0;

        EthereumSwapAttempt::query()
            ->where('status', 'submitted')
            ->whereNotNull('transaction_hash')
            ->orderBy('id')
            ->chunkById(100, function ($attempts) use (
                $ethereum,
                $reconciliation,
                &$checked,
                &$confirmed,
                &$failed,
                &$rejected
            ): void {
                foreach ($attempts as $attempt) {
                    $checked++;

                    try {
                        $receipt = $ethereum->getTransactionReceipt($attempt->transaction_hash);
                    } catch (RuntimeException $exception) {
                        $this->warn(
                            "Attempt {$attempt->id}: receipt lookup temporarily unavailable."
                        );

                        continue;
                    }

                    if ($receipt === null) {
                        continue;
                    }

                    if ($receipt['transaction_hash'] !== strtolower($attempt->transaction_hash)) {
                        $this->warn(
                            "Attempt {$attempt->id}: receipt hash did not match the stored transaction hash."
                        );

                        continue;
                    }

                    try {
                        $status = $reconciliation->apply($attempt, $receipt);
                    } catch (DomainException) {
                        $rejected++;
                        $this->warn("Attempt {$attempt->id}: LIVE accounting evidence rejected; confirmation rolled back. Manual review required.");

                        continue;
                    }
                    if ($status === 'confirmed') {
                        $confirmed++;
                    } elseif ($status === 'failed') {
                        $failed++;
                    }

                }
            });

        $this->info(
            "Checked {$checked} submitted Ethereum swap attempt(s): "
            ."{$confirmed} confirmed, {$failed} failed."
        );

        if ($rejected > 0) {
            $this->warn("Rejected {$rejected} attempt(s) due to LIVE accounting evidence; other attempts were processed.");
        }

        return $rejected === 0 ? self::SUCCESS : self::FAILURE;
    }
}
