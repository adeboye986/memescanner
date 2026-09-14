<?php

namespace App\Console\Commands;

use App\Models\EthereumSwapAttempt;
use App\Services\EthereumService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('ethereum:reconcile-submitted-swaps')]
#[Description('Check submitted Ethereum swaps and mark mined transactions as confirmed or failed.')]
class ReconcileSubmittedEthereumSwaps extends Command
{
    public function handle(EthereumService $ethereum): int
    {
        $checked = 0;
        $confirmed = 0;
        $failed = 0;

        EthereumSwapAttempt::query()
            ->where('status', 'submitted')
            ->whereNotNull('transaction_hash')
            ->orderBy('id')
            ->chunkById(100, function ($attempts) use (
                $ethereum,
                &$checked,
                &$confirmed,
                &$failed
            ): void {
                foreach ($attempts as $attempt) {
                    $checked++;

                    try {
                        $receipt = $ethereum->getTransactionReceipt($attempt->transaction_hash);
                    } catch (RuntimeException $exception) {
                        $this->warn(
                            "Attempt {$attempt->id}: receipt lookup failed: {$exception->getMessage()}"
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

                    if ($receipt['succeeded']) {
                        $updated = EthereumSwapAttempt::query()
                            ->whereKey($attempt->id)
                            ->where('status', 'submitted')
                            ->update([
                                'status' => 'confirmed',
                                'confirmed_at' => now(),
                                'failed_at' => null,
                                'failure_reason' => null,
                                'updated_at' => now(),
                            ]);

                        if ($updated === 1) {
                            $confirmed++;
                        }

                        continue;
                    }

                    $updated = EthereumSwapAttempt::query()
                        ->whereKey($attempt->id)
                        ->where('status', 'submitted')
                        ->update([
                            'status' => 'failed',
                            'failed_at' => now(),
                            'failure_reason' => 'Ethereum transaction reverted on-chain.',
                            'updated_at' => now(),
                        ]);

                    if ($updated === 1) {
                        $failed++;
                    }
                }
            });

        $this->info(
            "Checked {$checked} submitted Ethereum swap attempt(s): "
            ."{$confirmed} confirmed, {$failed} failed."
        );

        return self::SUCCESS;
    }
}
