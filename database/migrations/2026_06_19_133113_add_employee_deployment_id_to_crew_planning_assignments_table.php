<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crew_planning_assignments', function (Blueprint $table) {
            $table->foreignId('employee_deployment_id')
                ->nullable()
                ->after('employee_id')
                ->constrained('employee_deployments')
                ->nullOnDelete();

            $table->unique('employee_deployment_id');
        });
    }

    public function down(): void
    {
        Schema::table('crew_planning_assignments', function (Blueprint $table) {
            $table->dropUnique(['employee_deployment_id']);
            $table->dropConstrainedForeignId('employee_deployment_id');
        });
    }
};
