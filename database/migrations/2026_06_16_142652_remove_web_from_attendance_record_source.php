<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('attendance_records')
            ->where('source', 'web')
            ->update(['source' => null]);

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->enum('source', ['manual', 'biometric', 'mobile'])->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->enum('source', ['manual', 'biometric', 'mobile', 'web'])->nullable()->default(null)->change();
        });
    }
};
