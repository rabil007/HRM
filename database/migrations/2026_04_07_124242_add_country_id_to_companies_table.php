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
        DB::table('countries')->updateOrInsert(
            ['code' => 'UAE'],
            [
                'name' => 'United Arab Emirates',
                'dial_code' => '+971',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('country_id')->nullable()->after('tax_id')->constrained('countries');
        });

        DB::table('companies')
            ->whereNull('country_id')
            ->update([
                'country_id' => DB::raw('(SELECT id FROM countries WHERE code = companies.country LIMIT 1)'),
            ]);

        DB::table('companies')
            ->whereNull('country_id')
            ->update([
                'country_id' => DB::raw('(SELECT id FROM countries WHERE code = "UAE" LIMIT 1)'),
            ]);

        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('country_id')->nullable(false)->change();
            $table->dropColumn('country');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('country', 100)->default('UAE')->after('tax_id');
        });

        DB::table('companies')->update([
            'country' => DB::raw('(SELECT code FROM countries WHERE countries.id = companies.country_id LIMIT 1)'),
        ]);

        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('country_id');
        });
    }
};
