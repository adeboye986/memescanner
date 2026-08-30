<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('token_scans', function (Blueprint $table) {
            $table->string('follow_up_status')
                ->nullable()
                ->after('score');

            $table->timestamp('last_follow_up_alerted_at')
                ->nullable()
                ->after('follow_up_status');
        });
    }

    public function down(): void
    {
        Schema::table('token_scans', function (Blueprint $table) {
            $table->dropColumn([
                'follow_up_status',
                'last_follow_up_alerted_at',
            ]);
        });
    }
};