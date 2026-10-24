<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('system_activities', function (Blueprint $table) {
            $table->json('result_summary')->nullable()->after('error_message');
            $table->timestamp('last_progress_at')->nullable()->after('result_summary');

            $table->index(['status', 'last_progress_at'], 'system_activities_status_progress_idx');
            $table->index(['action', 'status', 'last_progress_at'], 'system_activities_action_status_progress_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('system_activities', function (Blueprint $table) {
            $table->dropIndex('system_activities_action_status_progress_idx');
            $table->dropIndex('system_activities_status_progress_idx');
            $table->dropColumn(['result_summary', 'last_progress_at']);
        });
    }
};
