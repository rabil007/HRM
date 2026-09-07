<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_trainings', function (Blueprint $table) {
            $table->foreignId('source_crew_assignment_phase_id')
                ->nullable()
                ->after('certificate_path')
                ->constrained('crew_assignment_phases')
                ->nullOnDelete();
            $table->unique('source_crew_assignment_phase_id');
        });
    }

    public function down(): void
    {
        Schema::table('employee_trainings', function (Blueprint $table) {
            $table->dropForeign(['source_crew_assignment_phase_id']);
            $table->dropUnique(['source_crew_assignment_phase_id']);
            $table->dropColumn('source_crew_assignment_phase_id');
        });
    }
};
