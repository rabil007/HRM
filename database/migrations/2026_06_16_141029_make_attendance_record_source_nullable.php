<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->enum('source', ['manual', 'biometric', 'mobile', 'web'])->nullable()->default(null)->change();
        });

        DB::table('attendance_records')
            ->whereNull('clock_in')
            ->whereNull('clock_out')
            ->where('source', 'web')
            ->update(['source' => null]);
    }

    public function down(): void
    {
        DB::table('attendance_records')
            ->whereNull('source')
            ->update(['source' => 'web']);

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->enum('source', ['manual', 'biometric', 'mobile', 'web'])->default('web')->change();
        });
    }
};
