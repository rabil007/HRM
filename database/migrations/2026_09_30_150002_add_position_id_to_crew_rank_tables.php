<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crew_assignments', function (Blueprint $table): void {
            $table->foreignId('position_id')
                ->nullable()
                ->after('rank_id')
                ->constrained('positions')
                ->nullOnDelete();
            $table->index(['company_id', 'position_id'], 'crew_assignments_company_position_index');
        });

        Schema::table('crew_planning_assignments', function (Blueprint $table): void {
            $table->foreignId('position_id')
                ->nullable()
                ->after('rank_id')
                ->constrained('positions')
                ->nullOnDelete();
            $table->index(['company_id', 'position_id'], 'cpa_company_position');
        });

        Schema::table('employee_sea_services', function (Blueprint $table): void {
            $table->foreignId('position_id')
                ->nullable()
                ->after('rank_id')
                ->constrained('positions')
                ->restrictOnDelete();
            $table->index(['company_id', 'position_id'], 'employee_sea_services_company_position_index');
        });

        Schema::table('vessel_manning', function (Blueprint $table): void {
            $table->foreignId('position_id')
                ->nullable()
                ->after('rank_id')
                ->constrained('positions')
                ->restrictOnDelete();
            $table->index(['company_id', 'position_id'], 'vessel_manning_company_position_index');
        });
    }

    public function down(): void
    {
        Schema::table('crew_assignments', function (Blueprint $table): void {
            $table->dropIndex('crew_assignments_company_position_index');
            $table->dropConstrainedForeignId('position_id');
        });

        Schema::table('crew_planning_assignments', function (Blueprint $table): void {
            $table->dropIndex('cpa_company_position');
            $table->dropConstrainedForeignId('position_id');
        });

        Schema::table('employee_sea_services', function (Blueprint $table): void {
            $table->dropIndex('employee_sea_services_company_position_index');
            $table->dropConstrainedForeignId('position_id');
        });

        Schema::table('vessel_manning', function (Blueprint $table): void {
            $table->dropIndex('vessel_manning_company_position_index');
            $table->dropConstrainedForeignId('position_id');
        });
    }
};
