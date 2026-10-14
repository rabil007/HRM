<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_inputs', function (Blueprint $table) {
            $table->boolean('crew_operations_timesheet_import_owned')
                ->default(false)
                ->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('salary_inputs', function (Blueprint $table) {
            $table->dropColumn('crew_operations_timesheet_import_owned');
        });
    }
};
