<?php

namespace App\Console\Commands;

use App\Services\TradingEngine\TradingEngineLiveAttemptInspector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('trading-engine:live-attempts
    {--chain=all : Inspect ethereum, solana, or all}
    {--limit=100 : Maximum unresolved attempts to show per chain (1-500)}')]
#[Description('Show read-only operational status for unresolved Ethereum and Solana LIVE attempts.')]
class InspectTradingEngineLiveAttempts extends Command
{
    public function handle(TradingEngineLiveAttemptInspector $inspector): int
    {
        $chain = strtolower(trim((string) $this->option('chain')));
        $limit = (string) $this->option('limit');

        if (! in_array($chain, ['all', 'ethereum', 'solana'], true)) {
            $this->error('The --chain option must be ethereum, solana, or all.');

            return self::INVALID;
        }

        if (preg_match('/^[1-9][0-9]{0,2}$/D', $limit) !== 1 || (int) $limit > 500) {
            $this->error('The --limit option must be an integer from 1 to 500.');

            return self::INVALID;
        }

        $attempts = $inspector->inspect($chain, (int) $limit);
        $this->info("Found {$attempts->count()} active or unresolved LIVE attempt(s). Showing at most {$limit} per chain.");

        if ($attempts->isEmpty()) {
            return self::SUCCESS;
        }

        $this->table(
            ['Chain', 'Attempt', 'Opportunity', 'Status', 'Transaction evidence', 'Origin', 'Age (s)', 'Expiry', 'Eligible', 'Classification', 'Reason'],
            $attempts->map(fn (array $attempt): array => [
                $attempt['chain'],
                (string) $attempt['attempt_id'],
                $attempt['opportunity_id'] === null ? '-' : (string) $attempt['opportunity_id'],
                $attempt['status'],
                $attempt['transaction_evidence'] ?? '-',
                $attempt['preparation_origin'] ?? '-',
                (string) $attempt['age_seconds'],
                $attempt['expires_at'] ?? '-',
                $attempt['reconciliation_eligible'] ? 'yes' : 'no',
                $attempt['classification'],
                $attempt['reason'],
            ])->all(),
        );

        return self::SUCCESS;
    }
}
