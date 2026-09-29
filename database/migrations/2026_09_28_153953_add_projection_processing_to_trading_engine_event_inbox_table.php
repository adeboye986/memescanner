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
        Schema::table('trading_engine_event_inbox', function (Blueprint $table) {
            $table->unsignedInteger('handling_attempts')->default(0)->after('handling_status');
            $table->string('handling_error_code', 64)->nullable()->after('handling_attempts');
            $table->timestampTz('next_handling_at')->nullable()->index()->after('handling_error_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trading_engine_event_inbox', function (Blueprint $table) {
            $table->dropIndex(['next_handling_at']);
            $table->dropColumn([
                'handling_attempts',
                'handling_error_code',
                'next_handling_at',
            ]);
        });
    }
};
