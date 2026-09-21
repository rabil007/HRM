<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_request_approval_reassignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_request_id')->constrained('leave_requests')->cascadeOnDelete();
            $table->unsignedBigInteger('leave_request_approval_id')->nullable();
            $table->unsignedSmallInteger('sequence');
            $table->string('policy_step_label')->nullable();
            $table->unsignedBigInteger('from_approver_employee_id')->nullable();
            $table->unsignedBigInteger('from_approver_user_id')->nullable();
            $table->string('from_approver_name')->nullable();
            $table->unsignedBigInteger('to_approver_employee_id')->nullable();
            $table->unsignedBigInteger('to_approver_user_id')->nullable();
            $table->string('to_approver_name');
            $table->text('reason');
            $table->unsignedBigInteger('reassigned_by_user_id')->nullable();
            $table->string('reassigned_by_name')->nullable();
            $table->timestamps();

            $table->foreign('leave_request_approval_id', 'leave_req_appr_reassign_approval_fk')
                ->references('id')
                ->on('leave_request_approvals')
                ->nullOnDelete();

            $table->foreign('from_approver_employee_id', 'leave_req_appr_reassign_from_emp_fk')
                ->references('id')
                ->on('employees')
                ->nullOnDelete();

            $table->foreign('from_approver_user_id', 'leave_req_appr_reassign_from_user_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign('to_approver_employee_id', 'leave_req_appr_reassign_to_emp_fk')
                ->references('id')
                ->on('employees')
                ->nullOnDelete();

            $table->foreign('to_approver_user_id', 'leave_req_appr_reassign_to_user_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign('reassigned_by_user_id', 'leave_req_appr_reassign_by_user_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->index(['company_id', 'leave_request_id'], 'leave_req_appr_reassign_company_request_idx');
            $table->index(['leave_request_approval_id'], 'leave_req_appr_reassign_approval_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_request_approval_reassignments');
    }
};
