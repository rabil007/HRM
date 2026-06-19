<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crew_planning_assignments', function (Blueprint $table) {
            $table->unsignedBigInteger('relieves_employee_deployment_id')
                ->nullable()
                ->after('employee_deployment_id');

            $table->foreign('relieves_employee_deployment_id', 'cpa_relieves_deployment_fk')
                ->references('id')
                ->on('employee_deployments')
                ->nullOnDelete();

            $table->index('relieves_employee_deployment_id', 'cpa_relieves_deployment_idx');
        });
    }

    public function down(): void
    {
        Schema::table('crew_planning_assignments', function (Blueprint $table) {
            $table->dropForeign('cpa_relieves_deployment_fk');
            $table->dropIndex('cpa_relieves_deployment_idx');
            $table->dropColumn('relieves_employee_deployment_id');
        });
    }
};
