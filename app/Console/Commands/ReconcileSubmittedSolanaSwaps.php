<?php

namespace App\Console\Commands;

use App\Models\SolanaSwapAttempt;
use App\Services\SolanaService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('solana:reconcile-submitted-swaps')]
#[Description('Reconcile submitted Solana swaps with their on-chain transaction status.')]
class ReconcileSubmittedSolanaSwaps extends Command
{
    public function handle(SolanaService $solana): int
    {
        $confirmed = 0;
        $failed = 0;
        $pending = 0;
        $errors = 0;

        SolanaSwapAttempt::query()
            ->whereIn('status', ['submitting', 'submitted'])
            ->whereNotNull('transaction_signature')
            ->chunkById(100, function ($attempts) use (
                $solana,
                &$confirmed,
                &$failed,
                &$pending,
                &$errors,
            ): void {
                foreach ($attempts as $attempt) {
                    try {
                        $receipt = $solana->getTransactionReceipt(
                            $attempt->transaction_signature
                        );
                    } catch (Throwable $exception) {
                        $errors++;

                        $this->warn(
                            "Could not reconcile Solana swap attempt {$attempt->id}: {$exception->getMessage()}"
                        );

                        continue;
                    }

                    if ($receipt === null) {
                        try {
                            $blockhashValid = $attempt->recent_blockhash === null
                                || $solana->isBlockhashValid($attempt->recent_blockhash);
                        } catch (Throwable $exception) {
                            $errors++;

                            $this->warn(
                                "Could not check Solana blockhash for swap attempt {$attempt->id}: {$exception->getMessage()}"
                            );

                            continue;
                        }

                        if ($blockhashValid) {
                            $pending++;

                            continue;
                        }

                        $updated = SolanaSwapAttempt::query()
                            ->whereKey($attempt->id)
                            ->whereIn('status', ['submitting', 'submitted'])
                            ->update([
                                'status' => 'failed',
                                'failed_at' => now(),
                                'failure_reason' => 'Solana transaction was not found on-chain before its blockhash expired.',
                                'updated_at' => now(),
                            ]);

                        if ($updated === 1) {
                            $failed++;
                        }

                        continue;
                    }

                    if ($receipt['succeeded']) {
                        $updated = SolanaSwapAttempt::query()
                            ->whereKey($attempt->id)
                            ->whereIn('status', ['submitting', 'submitted'])
                            ->update([
                                'status' => 'confirmed',
                                'confirmed_at' => now(),
                                'failed_at' => null,
                                'failure_reason' => null,
                                'network_fee_lamports' => $receipt['network_fee_lamports'],
                                'slot' => $receipt['slot'],
                                'updated_at' => now(),
                            ]);

                        if ($updated === 1) {
                            $confirmed++;
                        }

                        continue;
                    }

                    $updated = SolanaSwapAttempt::query()
                        ->whereKey($attempt->id)
                        ->whereIn('status', ['submitting', 'submitted'])
                        ->update([
                            'status' => 'failed',
                            'failed_at' => now(),
                            'failure_reason' => 'Solana transaction failed on-chain.',
                            'network_fee_lamports' => $receipt['network_fee_lamports'],
                            'slot' => $receipt['slot'],
                            'updated_at' => now(),
                        ]);

                    if ($updated === 1) {
                        $failed++;
                    }
                }
            });

        $this->info(
            "Confirmed {$confirmed}, failed {$failed}, pending {$pending}, RPC errors {$errors}."
        );

        return self::SUCCESS;
    }
}
