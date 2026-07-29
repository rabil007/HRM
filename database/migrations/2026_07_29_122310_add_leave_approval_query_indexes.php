<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive indexes for leave approval overlap checks, listing, and actor scopes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table): void {
            if (! Schema::hasIndex('leave_requests', 'idx_lr_company_emp_status_dates')) {
                // Overlap + employee timeline scans under company scope.
                $table->index(
                    ['company_id', 'employee_id', 'status', 'start_date', 'end_date'],
                    'idx_lr_company_emp_status_dates',
                );
            }

            if (! Schema::hasIndex('leave_requests', 'idx_lr_company_status_id')) {
                // Company leave-request listing sorted by latest id with status filters.
                $table->index(
                    ['company_id', 'status', 'id'],
                    'idx_lr_company_status_id',
                );
            }
        });

        Schema::table('leave_request_approvals', function (Blueprint $table): void {
            if (! Schema::hasIndex('leave_request_approvals', 'idx_lra_company_approver_status')) {
                // Awaiting-my-approval / assigned-to-me actor scopes.
                $table->index(
                    ['company_id', 'approver_user_id', 'status'],
                    'idx_lra_company_approver_status',
                );
            }

            if (! Schema::hasIndex('leave_request_approvals', 'idx_lra_company_policy')) {
                // Policy deletion safety checks against historical snapshots.
                $table->index(
                    ['company_id', 'policy_id'],
                    'idx_lra_company_policy',
                );
            }
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table): void {
            if (Schema::hasIndex('leave_requests', 'idx_lr_company_emp_status_dates')) {
                $table->dropIndex('idx_lr_company_emp_status_dates');
            }

            if (Schema::hasIndex('leave_requests', 'idx_lr_company_status_id')) {
                $table->dropIndex('idx_lr_company_status_id');
            }
        });

        Schema::table('leave_request_approvals', function (Blueprint $table): void {
            if (Schema::hasIndex('leave_request_approvals', 'idx_lra_company_approver_status')) {
                $table->dropIndex('idx_lra_company_approver_status');
            }

            if (Schema::hasIndex('leave_request_approvals', 'idx_lra_company_policy')) {
                $table->dropIndex('idx_lra_company_policy');
            }
        });
    }
};
