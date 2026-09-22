<?php

namespace App\Console\Commands;

use App\Models\LivePosition;
use App\Services\EthereumInventoryAccounting;
use Illuminate\Console\Command;

class ReconcileEthereumInventory extends Command
{
    protected $signature = 'ethereum:reconcile-inventory {--audit-verified : Recheck previously verified evidence} {--retry-unsupported : Recheck after a new eligibility review or capability change}';

    protected $description = 'Reconcile bounded Ethereum LIVE inventory evidence without signing or broadcasting.';

    public function handle(EthereumInventoryAccounting $accounting): int
    {
        $audit = (bool) $this->option('audit-verified');
        $retry = (bool) $this->option('retry-unsupported');
        $states = ['pending', 'provisional', ...($audit ? ['verified'] : []), ...($retry ? ['unsupported'] : [])];
        $positions = LivePosition::query()->where('chain', 'ethereum')->whereIn('accounting_status', $states)
            ->where(fn ($query) => $query->whereNull('accounting_lease_expires_at')->orWhere('accounting_lease_expires_at', '<=', now()));
        if (! $audit && ! $retry) {
            $positions->where(fn ($query) => $query->whereNull('accounting_next_attempt_at')->orWhere('accounting_next_attempt_at', '<=', now()));
        }
        $ids = $positions->orderBy('accounting_last_attempt_at')->orderBy('id')
            ->limit(max(1, min(100, (int) config('services.ethereum.accounting.batch_size', 25))))->pluck('id');
        $unsuccessful = false;
        foreach ($ids as $id) {
            $state = $accounting->process($id, $audit, $retry);
            $this->line("Position {$id}: {$state}.");
            $unsuccessful = $unsuccessful || in_array($state, ['discrepancy', 'unsupported', 'retryable', 'stale_observation'], true);
        }
        $this->info('Processed '.$ids->count().' Ethereum inventory candidate(s).');

        return $unsuccessful ? self::FAILURE : self::SUCCESS;
    }
}
