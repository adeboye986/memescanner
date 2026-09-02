<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $hasLegacyDefaultWallet = DB::table('paper_wallets')->where('name', 'default')->exists();

        Schema::table('paper_wallets', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->string('chain')->default('solana')->after('name');
            $table->string('currency')->default('SOL')->after('chain');
            $table->unique(['name', 'chain']);
        });

        DB::table('paper_wallets')->where('chain', 'solana')->update(['currency' => 'SOL']);

        if ($hasLegacyDefaultWallet && ! DB::table('paper_wallets')->where('name', 'default')->where('chain', 'ethereum')->exists()) {
            DB::table('paper_wallets')->insert([
                'name' => 'default',
                'chain' => 'ethereum',
                'currency' => 'ETH',
                'starting_balance_sol' => 5,
                'available_balance_sol' => 5,
                'invested_balance_sol' => 0,
                'realized_pnl_sol' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('paper_wallets')->where('chain', 'ethereum')->delete();

        Schema::table('paper_wallets', function (Blueprint $table) {
            $table->dropUnique(['name', 'chain']);
            $table->dropColumn(['chain', 'currency']);
            $table->unique('name');
        });
    }
};
