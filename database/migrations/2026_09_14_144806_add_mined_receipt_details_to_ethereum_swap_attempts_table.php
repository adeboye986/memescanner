<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ethereum_swap_attempts', function (Blueprint $table) {
            $table->string('block_number')->nullable()->after('failure_reason');
            $table->string('gas_used')->nullable()->after('block_number');
            $table->string('effective_gas_price_wei')->nullable()->after('gas_used');
            $table->string('actual_network_fee_wei')->nullable()->after('effective_gas_price_wei');
        });
    }

    public function down(): void
    {
        Schema::table('ethereum_swap_attempts', function (Blueprint $table) {
            $table->dropColumn([
                'block_number',
                'gas_used',
                'effective_gas_price_wei',
                'actual_network_fee_wei',
            ]);
        });
    }
};
