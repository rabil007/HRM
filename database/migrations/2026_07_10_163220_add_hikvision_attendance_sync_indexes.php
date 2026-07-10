<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hikvision_access_events')
            && ! Schema::hasIndex('hikvision_access_events', 'idx_hv_events_time_person')) {
            Schema::table('hikvision_access_events', function (Blueprint $table) {
                $table->index(['occurrence_time', 'person_hikvision_id'], 'idx_hv_events_time_person');
            });
        }

        if (Schema::hasTable('leave_requests')
            && ! Schema::hasIndex('leave_requests', 'idx_lr_emp_status_dates')) {
            Schema::table('leave_requests', function (Blueprint $table) {
                $table->index(['employee_id', 'status', 'start_date', 'end_date'], 'idx_lr_emp_status_dates');
            });
        }

        if (Schema::hasTable('employees')
            && ! Schema::hasIndex('employees', 'idx_emp_company_status')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->index(['company_id', 'status'], 'idx_emp_company_status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('hikvision_access_events')
            && Schema::hasIndex('hikvision_access_events', 'idx_hv_events_time_person')) {
            Schema::table('hikvision_access_events', function (Blueprint $table) {
                $table->dropIndex('idx_hv_events_time_person');
            });
        }

        if (Schema::hasTable('leave_requests')
            && Schema::hasIndex('leave_requests', 'idx_lr_emp_status_dates')) {
            Schema::table('leave_requests', function (Blueprint $table) {
                $table->dropIndex('idx_lr_emp_status_dates');
            });
        }

        if (Schema::hasTable('employees')
            && Schema::hasIndex('employees', 'idx_emp_company_status')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropIndex('idx_emp_company_status');
            });
        }
    }
};
