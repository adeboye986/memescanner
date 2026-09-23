<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ethereum_accounting_reconsiderations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_uuid')->unique('eth_reconsideration_uuid');
            $table->unsignedBigInteger('requested_by_user_id');
            $table->foreign('requested_by_user_id', 'eth_reconsideration_actor_fk')->references('id')->on('users')->restrictOnDelete();
            $table->json('reviewer_identity');
            $table->string('chain', 30);
            $table->string('token_address', 42);
            $table->unsignedBigInteger('ethereum_accounting_eligibility_id');
            $table->foreign('ethereum_accounting_eligibility_id', 'eth_reconsideration_review_fk')->references('id')->on('ethereum_accounting_eligibilities')->restrictOnDelete();
            $table->unsignedBigInteger('review_head_version');
            $table->string('review_decision', 30);
            $table->string('policy_version', 80);
            $table->string('binding_digest', 64);
            $table->json('candidate_snapshot');
            $table->json('outcomes');
            $table->unsignedSmallInteger('batch_limit');
            foreach (['considered_count', 'skipped_count', 'succeeded_count', 'failed_count', 'stale_count', 'remaining_count'] as $column) {
                $table->unsignedInteger($column)->default(0);
            }
            $table->string('status', 30)->default('awaiting_confirmation');
            $table->timestamp('expires_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['chain', 'token_address', 'id'], 'eth_reconsideration_token');
            $table->index(['requested_by_user_id', 'created_at'], 'eth_reconsideration_actor');
        });
    }

    public function down(): void
    {
        if (DB::table('ethereum_accounting_reconsiderations')->exists()) {
            throw new LogicException('Cannot remove retained Ethereum reconsideration audit records.');
        }
        Schema::dropIfExists('ethereum_accounting_reconsiderations');
    }
};
