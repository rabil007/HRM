<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_sea_services', function (Blueprint $table) {
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
        Schema::table('employee_sea_services', function (Blueprint $table) {
            $table->dropForeign(['employee_deployment_id']);
            $table->dropUnique(['employee_deployment_id']);
            $table->dropColumn('employee_deployment_id');
        });
    }
};
