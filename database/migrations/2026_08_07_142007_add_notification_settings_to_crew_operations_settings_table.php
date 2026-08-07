<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crew_operations_settings', function (Blueprint $table) {
            $table->boolean('notifications_enabled')->default(false)->after('sync_sea_service');
            $table->json('notification_recipient_user_ids')->nullable()->after('notifications_enabled');
            $table->boolean('alert_signoff_overdue')->default(true)->after('notification_recipient_user_ids');
            $table->boolean('alert_signoff_no_relief')->default(true)->after('alert_signoff_overdue');
            $table->boolean('alert_relief_not_ready')->default(true)->after('alert_signoff_no_relief');
            $table->boolean('alert_current_manning_gap')->default(true)->after('alert_relief_not_ready');
            $table->boolean('alert_projected_manning_gap')->default(true)->after('alert_current_manning_gap');
            $table->boolean('notify_in_app')->default(true)->after('alert_projected_manning_gap');
            $table->boolean('notify_browser_push')->default(true)->after('notify_in_app');
            $table->boolean('notify_email')->default(false)->after('notify_browser_push');
        });
    }

    public function down(): void
    {
        Schema::table('crew_operations_settings', function (Blueprint $table) {
            $table->dropColumn([
                'notifications_enabled',
                'notification_recipient_user_ids',
                'alert_signoff_overdue',
                'alert_signoff_no_relief',
                'alert_relief_not_ready',
                'alert_current_manning_gap',
                'alert_projected_manning_gap',
                'notify_in_app',
                'notify_browser_push',
                'notify_email',
            ]);
        });
    }
};
