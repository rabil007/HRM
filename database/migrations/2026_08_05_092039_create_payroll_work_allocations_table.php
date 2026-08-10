<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payroll_work_allocations')) {
            return;
        }

        Schema::create('payroll_work_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('employee_id')
                ->constrained('employees', indexName: 'pwa_employee_fk')
                ->restrictOnDelete();
            $table->foreignId('payroll_period_id')
                ->constrained('payroll_periods', indexName: 'pwa_period_fk')
                ->restrictOnDelete();
            // nullOnDelete keeps reversed allocation history if a draft record is force-deleted.
            // Approved cancel no longer deletes records — payroll_record_id stays linked.
            $table->foreignId('payroll_record_id')
                ->nullable()
                ->constrained('payroll_records', indexName: 'pwa_record_fk')
                ->nullOnDelete();
            $table->foreignId('crew_timesheet_id')
                ->nullable()
                ->constrained('crew_timesheets', indexName: 'pwa_timesheet_fk')
                ->nullOnDelete();
            $table->foreignId('crew_timesheet_segment_id')
                ->nullable()
                ->constrained('crew_timesheet_segments', indexName: 'pwa_segment_fk')
                ->nullOnDelete();
            $table->date('work_date');
            $table->string('pay_category', 32);
            $table->string('period_classification', 32);
            $table->string('status', 32);
            $table->string('source', 32)->nullable();
            $table->foreignId('crew_assignment_id')
                ->nullable()
                ->constrained('crew_assignments', indexName: 'pwa_assignment_fk')
                ->nullOnDelete();
            $table->foreignId('crew_assignment_phase_id')
                ->nullable()
                ->constrained('crew_assignment_phases', indexName: 'pwa_phase_fk')
                ->nullOnDelete();
            $table->foreignId('contract_id')
                ->constrained('employee_contracts', indexName: 'pwa_contract_fk')
                ->restrictOnDelete();
            // Maps to contract_salary_revisions.id (historical rate package for the work date).
            $table->foreignId('salary_revision_id')
                ->nullable()
                ->constrained('contract_salary_revisions', indexName: 'pwa_revision_fk')
                ->nullOnDelete();
            $table->decimal('basic_daily_rate', 12, 2);
            $table->decimal('site_allowance_daily_rate', 12, 2)->default(0);
            $table->decimal('supplementary_allowance_daily_rate', 12, 2)->default(0);
            $table->decimal('basic_amount', 12, 2);
            $table->decimal('site_allowance_amount', 12, 2)->default(0);
            $table->decimal('supplementary_allowance_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2);
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            // Set for reserved|approved|paid as "{company_id}:{employee_id}:{work_date}"; null when reversed.
            $table->string('active_allocation_key', 64)->nullable()->unique('pwa_active_allocation_key_unique');
            $table->timestamps();

            $table->index(['company_id', 'payroll_period_id'], 'pwa_company_period_idx');
            $table->index(['company_id', 'payroll_record_id'], 'pwa_company_record_idx');
            $table->index(['company_id', 'employee_id', 'payroll_period_id'], 'pwa_company_employee_period_idx');
            $table->index(['company_id', 'employee_id', 'work_date'], 'pwa_company_employee_work_date_idx');
            $table->index(['company_id', 'work_date'], 'pwa_company_work_date_idx');
            $table->index(['company_id', 'status'], 'pwa_company_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_work_allocations');
    }
};
