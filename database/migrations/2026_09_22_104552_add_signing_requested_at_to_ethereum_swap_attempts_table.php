<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ethereum_swap_attempts', function (Blueprint $table): void {
            $table->timestamp('signing_requested_at')->nullable();
            $table->string('signing_claim_hash', 64)->nullable();
            $table->timestamp('signing_armed_at')->nullable();
        });
    }

    /** Stop writers first; never remove an unresolved one-time signing guard. */
    public function down(): void
    {
        if (DB::table('ethereum_swap_attempts')->where(fn ($query) => $query->whereNotNull('signing_requested_at')->orWhereNotNull('signing_armed_at')->orWhereNotNull('signing_claim_hash'))->whereNull('transaction_hash')
            ->whereNotIn('status', ['cancelled', 'failed'])->exists()) {
            throw new LogicException('Resolve signing outcomes before removing the Ethereum signing guard.');
        }
        Schema::table('ethereum_swap_attempts', function (Blueprint $table): void {
            $table->dropColumn(['signing_requested_at', 'signing_claim_hash', 'signing_armed_at']);
        });
    }
};
