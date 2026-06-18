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
        Schema::table('crew_operations_settings', function (Blueprint $table) {
            $table->unsignedInteger('max_home_days')->default(30)->after('pool_department_ids');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('crew_operations_settings', function (Blueprint $table) {
            $table->dropColumn('max_home_days');
        });
    }
};
