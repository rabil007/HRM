<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_sea_services', function (Blueprint $table) {
            $table->string('vessel_name', 255)->nullable()->after('vessel_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('employee_sea_services', function (Blueprint $table) {
            $table->dropColumn('vessel_name');
        });
    }
};
