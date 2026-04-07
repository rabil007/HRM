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
        DB::table('currencies')->updateOrInsert(
            ['code' => 'AED'],
            [
                'name' => 'UAE Dirham',
                'symbol' => 'د.إ',
                'precision' => 2,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('currency_id')->nullable()->after('website')->constrained('currencies');
        });

        DB::table('companies')
            ->whereNull('currency_id')
            ->update([
                'currency_id' => DB::raw('(SELECT id FROM currencies WHERE code = companies.currency LIMIT 1)'),
            ]);

        DB::table('companies')
            ->whereNull('currency_id')
            ->update([
                'currency_id' => DB::raw('(SELECT id FROM currencies WHERE code = "AED" LIMIT 1)'),
            ]);

        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('currency_id')->nullable(false)->change();
            $table->dropColumn('currency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('currency', 10)->default('AED')->after('website');
        });

        DB::table('companies')->update([
            'currency' => DB::raw('(SELECT code FROM currencies WHERE currencies.id = companies.currency_id LIMIT 1)'),
        ]);

        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('currency_id');
        });
    }
};
