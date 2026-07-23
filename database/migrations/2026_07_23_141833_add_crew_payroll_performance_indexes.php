<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crew_timesheets', function (Blueprint $table): void {
            if (! Schema::hasIndex('crew_timesheets', 'ct_company_period_approval_idx')) {
                $table->index(
                    ['company_id', 'period_id', 'approval_status'],
                    'ct_company_period_approval_idx',
                );
            }
        });

        Schema::table('payroll_records', function (Blueprint $table): void {
            if (! Schema::hasIndex('payroll_records', 'pr_company_period_category_idx')) {
                $table->index(
                    ['company_id', 'period_id', 'payroll_category'],
                    'pr_company_period_category_idx',
                );
            }
        });

        Schema::table('crew_timesheet_preparations', function (Blueprint $table): void {
            if (! Schema::hasIndex('crew_timesheet_preparations', 'ctp_company_period_status_idx')) {
                $table->index(
                    ['company_id', 'payroll_period_id', 'status'],
                    'ctp_company_period_status_idx',
                );
            }
        });
    }

    public function down(): void
    {
        Schema::table('crew_timesheets', function (Blueprint $table): void {
            if (Schema::hasIndex('crew_timesheets', 'ct_company_period_approval_idx')) {
                $table->dropIndex('ct_company_period_approval_idx');
            }
        });

        Schema::table('payroll_records', function (Blueprint $table): void {
            if (Schema::hasIndex('payroll_records', 'pr_company_period_category_idx')) {
                $table->dropIndex('pr_company_period_category_idx');
            }
        });

        Schema::table('crew_timesheet_preparations', function (Blueprint $table): void {
            if (Schema::hasIndex('crew_timesheet_preparations', 'ctp_company_period_status_idx')) {
                $table->dropIndex('ctp_company_period_status_idx');
            }
        });
    }
};
