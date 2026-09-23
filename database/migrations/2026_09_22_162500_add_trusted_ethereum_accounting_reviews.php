<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ethereum_accounting_eligibilities', function (Blueprint $table): void {
            $table->foreignId('reviewer_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->json('reviewer_identity')->nullable();
            $table->unsignedBigInteger('chain_id')->nullable();
            $table->string('review_format_version', 40)->nullable();
            $table->foreignId('supersedes_review_id')->nullable();
            $table->foreign('supersedes_review_id')->references('id')->on('ethereum_accounting_eligibilities')->restrictOnDelete();
            $table->string('observation_block_number', 78)->nullable();
            $table->string('observation_block_hash', 66)->nullable();
            $table->timestamp('evidence_collected_at')->nullable();
            $table->json('review_evidence')->nullable();
            $table->string('evidence_digest', 64)->nullable();
            $table->uuid('submission_id')->nullable()->unique();
        });
        Schema::create('ethereum_accounting_review_heads', function (Blueprint $table): void {
            $table->id();
            $table->string('chain', 30);
            $table->string('token_address', 42);
            $table->foreignId('current_review_id')->nullable();
            $table->foreign('current_review_id', 'eth_review_head_current_fk')->references('id')->on('ethereum_accounting_eligibilities')->restrictOnDelete();
            $table->unsignedBigInteger('version')->default(0);
            $table->timestamps();
            $table->unique(['chain', 'token_address'], 'eth_review_head_identity');
        });
    }

    public function down(): void
    {
        if (DB::table('ethereum_accounting_eligibilities')->whereNotNull('review_format_version')->exists()) {
            throw new LogicException('Cannot remove retained trusted review provenance.');
        }
        Schema::dropIfExists('ethereum_accounting_review_heads');
        Schema::table('ethereum_accounting_eligibilities', function (Blueprint $table): void {
            $table->dropForeign(['supersedes_review_id']);
            $table->dropConstrainedForeignId('reviewer_user_id');
            $table->dropUnique(['submission_id']);
            $table->dropColumn(['reviewer_identity', 'chain_id', 'review_format_version', 'supersedes_review_id',
                'observation_block_number', 'observation_block_hash', 'evidence_collected_at', 'review_evidence', 'evidence_digest', 'submission_id']);
        });
    }
};
