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
        Schema::table('employee_shifts', function (Blueprint $table) {
            $table->foreign('shift_id')
                ->references('id')
                ->on('shifts');
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->foreign('shift_id')
                ->references('id')
                ->on('shifts')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropForeign(['shift_id']);
        });

        Schema::table('employee_shifts', function (Blueprint $table) {
            $table->dropForeign(['shift_id']);
        });
    }
};
