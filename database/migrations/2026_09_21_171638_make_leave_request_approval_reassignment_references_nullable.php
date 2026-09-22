<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Correct production migration drift for leave_request_approval_reassignments.
 *
 * The originally deployed create migration used NOT NULL + cascade/restrict on
 * leave_request_approval_id / to_approver_* columns. History must survive
 * approval snapshot rebuild/deletion via nullable + nullOnDelete without
 * dropping reassignment rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leave_request_approval_reassignments')) {
            return;
        }

        $this->dropNamedForeign('leave_req_appr_reassign_approval_fk', 'leave_request_approval_id');
        $this->dropNamedForeign('leave_req_appr_reassign_to_emp_fk', 'to_approver_employee_id');
        $this->dropNamedForeign('leave_req_appr_reassign_to_user_fk', 'to_approver_user_id');

        Schema::table('leave_request_approval_reassignments', function (Blueprint $table): void {
            $table->unsignedBigInteger('leave_request_approval_id')->nullable()->change();
            $table->unsignedBigInteger('to_approver_employee_id')->nullable()->change();
            $table->unsignedBigInteger('to_approver_user_id')->nullable()->change();
        });

        Schema::table('leave_request_approval_reassignments', function (Blueprint $table): void {
            $table->foreign('leave_request_approval_id', 'leave_req_appr_reassign_approval_fk')
                ->references('id')
                ->on('leave_request_approvals')
                ->nullOnDelete();

            $table->foreign('to_approver_employee_id', 'leave_req_appr_reassign_to_emp_fk')
                ->references('id')
                ->on('employees')
                ->nullOnDelete();

            $table->foreign('to_approver_user_id', 'leave_req_appr_reassign_to_user_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Irreversible once null approval/approver references may exist after
        // approval snapshot rebuilds or related-row removal.
    }

    private function dropNamedForeign(string $name, string $column): void
    {
        try {
            Schema::table('leave_request_approval_reassignments', function (Blueprint $table) use ($name): void {
                $table->dropForeign($name);
            });
        } catch (Throwable) {
            try {
                Schema::table('leave_request_approval_reassignments', function (Blueprint $table) use ($column): void {
                    $table->dropForeign([$column]);
                });
            } catch (Throwable) {
                // FK may already be absent or named differently on a drifted local DB.
            }
        }
    }
};
