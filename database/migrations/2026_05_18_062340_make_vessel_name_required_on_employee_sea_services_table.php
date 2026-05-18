<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('employee_sea_services')
            ->whereNull('vessel_name')
            ->update(['vessel_name' => '']);

        Schema::table('employee_sea_services', function (Blueprint $table) {
            $table->string('vessel_name', 255)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('employee_sea_services', function (Blueprint $table) {
            $table->string('vessel_name', 255)->nullable()->change();
        });
    }
};
