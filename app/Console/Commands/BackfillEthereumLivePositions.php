<?php

namespace App\Console\Commands;

use App\Models\EthereumSwapAttempt;
use App\Services\LivePositionService;
use DomainException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ethereum:backfill-live-positions')]
#[Description('Create pending LIVE entry records from retained confirmed opportunity evidence; no provider calls.')]
class BackfillEthereumLivePositions extends Command
{
    public function handle(LivePositionService $positions): int
    {
        $created = 0;
        $existing = 0;
        $rejected = 0;
        EthereumSwapAttempt::query()->where('status', 'confirmed')->whereNotNull('trade_opportunity_id')->orderBy('id')
            ->chunkById(100, function ($attempts) use ($positions, &$created, &$existing, &$rejected): void {
                foreach ($attempts as $attempt) {
                    try {
                        $position = $positions->backfill($attempt);
                        if ($position->wasRecentlyCreated) {
                            $created++;
                        } else {
                            $existing++;
                        }
                    } catch (DomainException $exception) {
                        $rejected++;
                        $this->warn("Attempt {$attempt->id}: {$exception->getMessage()}");
                    }
                }
            });
        $this->info("Created {$created}, existing {$existing}, rejected {$rejected} LIVE entry records.");

        return $rejected === 0 ? self::SUCCESS : self::FAILURE;
    }
}
