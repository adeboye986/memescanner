<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ethereum_accounting_eligibilities', function (Blueprint $table): void {
            $table->id();
            $table->string('chain', 30)->default('ethereum');
            $table->string('token_address', 42);
            $table->string('policy_version', 80);
            $table->string('status', 30);
            $table->string('review_source', 255);
            $table->string('code_sha256', 64)->nullable();
            $table->timestamp('reviewed_at');
            $table->string('reason', 255);
            $table->timestamps();
            $table->index(['chain', 'token_address', 'id'], 'eth_accounting_eligibility_lookup');
        });
        Schema::create('ethereum_accounting_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('live_position_id')->constrained()->restrictOnDelete();
            $table->foreignId('ethereum_swap_attempt_id')->constrained()->restrictOnDelete();
            $table->foreignId('ethereum_accounting_eligibility_id')->nullable();
            $table->foreign('ethereum_accounting_eligibility_id', 'eth_evidence_eligibility_fk')->references('id')->on('ethereum_accounting_eligibilities')->restrictOnDelete();
            $table->string('state', 30);
            $table->string('reason_code', 80)->nullable();
            $table->string('digest', 64);
            $table->string('policy_version', 80);
            $table->json('evidence');
            $table->timestamps();
            $table->unique(['live_position_id', 'digest'], 'eth_accounting_evidence_unique');
        });
        Schema::table('live_positions', function (Blueprint $table): void {
            $table->string('accounting_policy_version', 80)->nullable();
            $table->timestamp('accounting_verified_at')->nullable();
            $table->string('accounting_reason_code', 80)->nullable();
            $table->unsignedInteger('accounting_retry_count')->default(0);
            $table->timestamp('accounting_last_attempt_at')->nullable();
            $table->timestamp('accounting_next_attempt_at')->nullable();
            $table->string('accounting_lease_token', 64)->nullable();
            $table->timestamp('accounting_lease_expires_at')->nullable();
            $table->unsignedBigInteger('accounting_version')->default(0);
            $table->foreignId('accepted_ethereum_evidence_id')->nullable()->constrained('ethereum_accounting_evidence')->restrictOnDelete();
            $table->index(['accounting_status', 'accounting_next_attempt_at'], 'live_accounting_due');
        });
    }

    /** Stop accounting writers before rollback; retained reviews/evidence are never dropped. */
    public function down(): void
    {
        if (DB::table('ethereum_accounting_evidence')->exists() || DB::table('ethereum_accounting_eligibilities')->exists()) {
            throw new LogicException('Cannot remove retained Ethereum accounting reviews or evidence.');
        }
        Schema::table('live_positions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('accepted_ethereum_evidence_id');
            $table->dropIndex('live_accounting_due');
            $table->dropColumn(['accounting_policy_version', 'accounting_verified_at', 'accounting_reason_code',
                'accounting_retry_count', 'accounting_last_attempt_at', 'accounting_next_attempt_at',
                'accounting_lease_token', 'accounting_lease_expires_at', 'accounting_version']);
        });
        Schema::dropIfExists('ethereum_accounting_evidence');
        Schema::dropIfExists('ethereum_accounting_eligibilities');
    }
};
