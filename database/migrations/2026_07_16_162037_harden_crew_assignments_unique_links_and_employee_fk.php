<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $deploymentDuplicates = DB::table('crew_assignments')
            ->select('employee_deployment_id')
            ->whereNotNull('employee_deployment_id')
            ->whereNull('deleted_at')
            ->groupBy('employee_deployment_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('employee_deployment_id');

        if ($deploymentDuplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot add unique index on crew_assignments.employee_deployment_id: duplicate non-null values exist for deployment IDs: '
                .$deploymentDuplicates->implode(', ')
            );
        }

        $planningDuplicates = DB::table('crew_assignments')
            ->select('crew_planning_assignment_id')
            ->whereNotNull('crew_planning_assignment_id')
            ->whereNull('deleted_at')
            ->groupBy('crew_planning_assignment_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('crew_planning_assignment_id');

        if ($planningDuplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot add unique index on crew_assignments.crew_planning_assignment_id: duplicate non-null values exist for planning assignment IDs: '
                .$planningDuplicates->implode(', ')
            );
        }

        Schema::table('crew_assignments', function (Blueprint $table) {
            $table->unique('employee_deployment_id');
            $table->unique('crew_planning_assignment_id');
        });

        Schema::table('crew_assignments', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
        });

        Schema::table('crew_assignments', function (Blueprint $table) {
            $table->foreign('employee_id')
                ->references('id')
                ->on('employees')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('crew_assignments', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
        });

        Schema::table('crew_assignments', function (Blueprint $table) {
            $table->foreign('employee_id')
                ->references('id')
                ->on('employees')
                ->cascadeOnDelete();
        });

        Schema::table('crew_assignments', function (Blueprint $table) {
            $table->dropUnique(['employee_deployment_id']);
            $table->dropUnique(['crew_planning_assignment_id']);
        });
    }
};
