<?php

use App\Models\TradeOpportunity;
use App\Services\EthereumOpportunityFreshness;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ethereum_swap_attempts', function (Blueprint $table): void {
            $table->uuid('preparation_token')->nullable();
            $table->timestamp('preparation_expires_at')->nullable();
            $table->json('revalidation_data')->nullable();
        });
    }

    /**
     * Stop application writers first. Preflight all Phase 2-only states before
     * mutating anything. Never reinterpret transaction evidence as a reservation.
     * Fresh, previously approved preparing rows return to reserved/Executing.
     * Released rows cannot represent explicit reapproval in Phase 1 and expire;
     * stale preparing rows expire too. Existing terminal opportunities stay terminal.
     * No attempt or opportunity is deleted. Published transaction rows are untouched.
     * The removed Phase 2 audit column must be archived before production rollback.
     * Commit data normalization before DDL because MySQL ALTER implicitly commits.
     */
    public function down(): void
    {
        DB::transaction(function (): void {
            $rows = DB::table('ethereum_swap_attempts')->whereIn('status', ['preparing', 'released'])->get();
            foreach ($rows as $row) {
                foreach (['quote_id', 'transaction_payload', 'expires_at', 'transaction_hash', 'submitted_at', 'confirmed_at',
                    'failed_at', 'block_number', 'gas_used', 'effective_gas_price_wei', 'actual_network_fee_wei'] as $field) {
                    if ($row->{$field} !== null) {
                        throw new LogicException('Refusing rollback: a Phase 2-only state contains transaction evidence.');
                    }
                }
                $opportunity = TradeOpportunity::query()->find($row->trade_opportunity_id);
                if (! $opportunity || $opportunity->user_id !== $row->user_id || $opportunity->chain->value !== 'ethereum') {
                    throw new LogicException('Refusing rollback: a Phase 2-only state has an inconsistent opportunity.');
                }
            }
            foreach ($rows as $row) {
                $opportunity = TradeOpportunity::query()->findOrFail($row->trade_opportunity_id);
                $reserved = $row->status === 'preparing' && $opportunity->status->value === 'executing'
                    && app(EthereumOpportunityFreshness::class)->isFresh($opportunity);
                DB::table('ethereum_swap_attempts')->where('id', $row->id)->update([
                    'status' => $reserved ? 'reserved' : 'expired', 'failure_reason' => $reserved ? null : 'phase_two_rollback',
                    'preparation_token' => null, 'preparation_expires_at' => null,
                ]);
                if (in_array($opportunity->status->value, ['executing', 'pending_confirmation'], true)) {
                    DB::table('trade_opportunities')->where('id', $opportunity->id)->update([
                        'status' => $reserved ? 'executing' : 'expired',
                        'execution_data' => json_encode([...($opportunity->execution_data ?? []), 'stage' => $reserved ? 'reserved' : 'expired', 'reason' => 'phase_two_rollback'], JSON_THROW_ON_ERROR),
                    ]);
                }
            }
        });
        Schema::table('ethereum_swap_attempts', function (Blueprint $table): void {
            $table->dropColumn(['preparation_token', 'preparation_expires_at', 'revalidation_data']);
        });
    }
};
