<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_leave_approval_settings', function (Blueprint $table) {
            $table->boolean('email_notifications_enabled')->default(true)->after('fallback_approver_employee_id');
            $table->boolean('notify_on_submission')->default(true)->after('email_notifications_enabled');
            $table->boolean('notify_on_update')->default(true)->after('notify_on_submission');
            $table->boolean('notify_next_approver')->default(true)->after('notify_on_update');
            $table->boolean('notify_on_final_decision')->default(true)->after('notify_next_approver');
            $table->boolean('copy_deciding_approver')->default(true)->after('notify_on_final_decision');
        });
    }

    public function down(): void
    {
        Schema::table('company_leave_approval_settings', function (Blueprint $table) {
            $table->dropColumn([
                'email_notifications_enabled',
                'notify_on_submission',
                'notify_on_update',
                'notify_next_approver',
                'notify_on_final_decision',
                'copy_deciding_approver',
            ]);
        });
    }
};
