<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payroll_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('period_id')->constrained('payroll_periods')->cascadeOnDelete();

            $table->decimal('basic_salary', 12, 2)->default(0);
            $table->decimal('housing_allowance', 12, 2)->default(0);
            $table->decimal('transport_allowance', 12, 2)->default(0);
            $table->decimal('other_allowances', 12, 2)->default(0);
            $table->decimal('overtime_pay', 12, 2)->default(0);
            $table->decimal('bonus', 12, 2)->default(0);
            $table->decimal('gross_salary', 12, 2)->default(0);

            $table->decimal('unpaid_leave_deduction', 12, 2)->default(0);
            $table->decimal('late_deduction', 12, 2)->default(0);
            $table->decimal('loan_deduction', 12, 2)->default(0);
            $table->decimal('other_deductions', 12, 2)->default(0);
            $table->decimal('total_deductions', 12, 2)->default(0);

            $table->decimal('net_salary', 12, 2)->default(0);

            $table->decimal('gratuity_accrued', 12, 2)->default(0);
            $table->decimal('gratuity_total', 12, 2)->default(0);

            $table->string('wps_reference', 100)->nullable();
            $table->string('wps_agent_ref', 100)->nullable();
            $table->enum('wps_status', ['pending', 'submitted', 'accepted', 'rejected'])->nullable();
            $table->timestamp('wps_submitted_at')->nullable();

            $table->unsignedInteger('working_days')->default(0);
            $table->unsignedInteger('present_days')->default(0);
            $table->unsignedInteger('absent_days')->default(0);
            $table->decimal('leave_days', 5, 2)->default(0);
            $table->decimal('overtime_hours', 6, 2)->default(0);

            $table->string('payslip_path', 500)->nullable();
            $table->enum('status', ['draft', 'approved', 'paid'])->default('draft');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'employee_id', 'period_id'], 'uq_payroll_emp_period');
            $table->index('company_id', 'idx_pr_company');
            $table->index('period_id', 'idx_pr_period');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payroll_records');
    }
};
