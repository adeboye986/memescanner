<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('solana_swap_attempts', function (Blueprint $table) {
            $table->timestamp('confirmed_at')->nullable()->after('submitted_at');
            $table->timestamp('failed_at')->nullable()->after('confirmed_at');
            $table->text('failure_reason')->nullable()->after('failed_at');
            $table->unsignedBigInteger('network_fee_lamports')->nullable()->after('failure_reason');
            $table->unsignedBigInteger('slot')->nullable()->after('network_fee_lamports');
        });
    }

    public function down(): void
    {
        Schema::table('solana_swap_attempts', function (Blueprint $table) {
            $table->dropColumn([
                'confirmed_at',
                'failed_at',
                'failure_reason',
                'network_fee_lamports',
                'slot',
            ]);
        });
    }
};
