<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trading_engine_paper_observations', function (Blueprint $table): void {
            $table->unsignedInteger('submission_attempt_count')->default(0);
            $table->unsignedSmallInteger('last_response_status')->nullable();
            $table->string('last_engine_error_code', 128)->nullable();
            $table->string('last_correlation_id', 128)->nullable();
            $table->timestampTz('last_attempted_at')->nullable();
            $table->uuid('recovery_token')->nullable()->unique();
            $table->string('recovery_previous_error_code', 128)->nullable();
            $table->timestampTz('recovery_requested_at')->nullable();
            $table->timestampTz('recovered_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('trading_engine_paper_observations', function (Blueprint $table): void {
            $table->dropUnique(['recovery_token']);
            $table->dropColumn([
                'submission_attempt_count',
                'last_response_status',
                'last_engine_error_code',
                'last_correlation_id',
                'last_attempted_at',
                'recovery_token',
                'recovery_previous_error_code',
                'recovery_requested_at',
                'recovered_at',
            ]);
        });
    }
};
