<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_request_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_request_id')->constrained('leave_requests')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('approver_type', 50);
            $table->unsignedBigInteger('approver_employee_id');
            $table->unsignedBigInteger('approver_user_id');
            $table->unsignedBigInteger('source_department_id')->nullable();
            $table->unsignedBigInteger('policy_step_id')->nullable();
            $table->string('status', 20)->default('waiting');
            $table->boolean('is_required')->default(true);
            $table->timestamp('acted_at')->nullable();
            $table->text('comments')->nullable();
            $table->timestamps();

            $table->foreign('approver_employee_id', 'leave_req_approvals_employee_fk')
                ->references('id')
                ->on('employees')
                ->restrictOnDelete();

            $table->foreign('approver_user_id', 'leave_req_approvals_user_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->foreign('source_department_id', 'leave_req_approvals_source_dept_fk')
                ->references('id')
                ->on('departments')
                ->nullOnDelete();

            $table->foreign('policy_step_id', 'leave_req_approvals_policy_step_fk')
                ->references('id')
                ->on('leave_approval_policy_steps')
                ->nullOnDelete();

            $table->unique(['leave_request_id', 'sequence'], 'leave_request_approvals_request_sequence_unique');
            $table->index(['company_id', 'leave_request_id'], 'leave_req_approvals_company_request_index');
            $table->index(['company_id', 'approver_user_id', 'status'], 'leave_req_approvals_company_user_status_index');
            $table->index(['leave_request_id', 'status'], 'leave_req_approvals_request_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_request_approvals');
    }
};
