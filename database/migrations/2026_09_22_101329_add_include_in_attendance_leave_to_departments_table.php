<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            // Temporary default true so existing rows stay included on deploy.
            // New departments are defaulted to false in DepartmentController::store
            // when the field is omitted.
            $table->boolean('include_in_attendance_leave')->default(true)->after('status');
        });

        DB::table('departments')->update(['include_in_attendance_leave' => true]);
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn('include_in_attendance_leave');
        });
    }
};
