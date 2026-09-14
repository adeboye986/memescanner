<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ethereum_swap_attempts', function (Blueprint $table) {
            $table->timestamp('confirmed_at')->nullable()->after('submitted_at');
            $table->timestamp('failed_at')->nullable()->after('confirmed_at');
            $table->string('failure_reason', 255)->nullable()->after('failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('ethereum_swap_attempts', function (Blueprint $table) {
            $table->dropColumn([
                'confirmed_at',
                'failed_at',
                'failure_reason',
            ]);
        });
    }
};