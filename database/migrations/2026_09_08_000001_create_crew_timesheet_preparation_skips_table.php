<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crew_timesheet_preparation_skips')) {
            Schema::create('crew_timesheet_preparation_skips', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('companies', indexName: 'ctps_company_fk')->restrictOnDelete();
                $table->foreignId('crew_timesheet_preparation_id')
                    ->constrained('crew_timesheet_preparations', indexName: 'ctps_prep_fk')
                    ->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('employees', indexName: 'ctps_employee_fk')->restrictOnDelete();
                $table->text('reason');
                $table->foreignId('skipped_by')->nullable()->constrained('users', indexName: 'ctps_skipped_by_fk')->nullOnDelete();
                $table->timestamp('skipped_at');
                $table->foreignId('restored_by')->nullable()->constrained('users', indexName: 'ctps_restored_by_fk')->nullOnDelete();
                $table->timestamp('restored_at')->nullable();
                $table->timestamps();

                $table->unique(
                    ['company_id', 'crew_timesheet_preparation_id', 'employee_id'],
                    'ctps_company_prep_employee_uq',
                );
                $table->index(
                    ['crew_timesheet_preparation_id', 'restored_at'],
                    'ctps_prep_restored_idx',
                );
                $table->index('employee_id', 'ctps_employee_idx');
            });

            return;
        }

        if (! Schema::hasIndex('crew_timesheet_preparation_skips', 'ctps_company_prep_employee_uq')) {
            Schema::table('crew_timesheet_preparation_skips', function (Blueprint $table) {
                $table->unique(
                    ['company_id', 'crew_timesheet_preparation_id', 'employee_id'],
                    'ctps_company_prep_employee_uq',
                );
            });
        }

        if (! Schema::hasIndex('crew_timesheet_preparation_skips', 'ctps_prep_restored_idx')) {
            Schema::table('crew_timesheet_preparation_skips', function (Blueprint $table) {
                $table->index(
                    ['crew_timesheet_preparation_id', 'restored_at'],
                    'ctps_prep_restored_idx',
                );
            });
        }

        if (! Schema::hasIndex('crew_timesheet_preparation_skips', 'ctps_employee_idx')) {
            Schema::table('crew_timesheet_preparation_skips', function (Blueprint $table) {
                $table->index('employee_id', 'ctps_employee_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crew_timesheet_preparation_skips');
    }
};
