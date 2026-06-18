<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crew_planning_assignments', function (Blueprint $table) {
            $table->dropIndex('cpa_company_status');
            $table->dropConstrainedForeignId('employee_deployment_id');
            $table->dropColumn('status');
        });
    }

    public function down(): void
    {
        Schema::table('crew_planning_assignments', function (Blueprint $table) {
            $table->string('status', 20)->default('draft')->after('planned_leave_date');
            $table->foreignId('employee_deployment_id')
                ->nullable()
                ->after('employee_id')
                ->constrained('employee_deployments')
                ->nullOnDelete();

            $table->index(['company_id', 'status'], 'cpa_company_status');
        });
    }
};
