<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crew_operations_settings', function (Blueprint $table) {
            $table->string('notification_email_delivery_mode', 20)
                ->default('scheduled')
                ->after('alert_projected_manning_gap');
            $table->string('notification_email_digest_at', 5)
                ->default('08:00')
                ->after('notification_email_delivery_mode');
            $table->boolean('notification_email_critical_immediate')
                ->default(true)
                ->after('notification_email_digest_at');
            $table->date('notification_email_last_digest_date')
                ->nullable()
                ->after('notification_email_critical_immediate');
            $table->timestamp('notification_email_last_digest_dispatched_at')
                ->nullable()
                ->after('notification_email_last_digest_date');
        });
    }

    public function down(): void
    {
        Schema::table('crew_operations_settings', function (Blueprint $table) {
            $table->dropColumn([
                'notification_email_delivery_mode',
                'notification_email_digest_at',
                'notification_email_critical_immediate',
                'notification_email_last_digest_date',
                'notification_email_last_digest_dispatched_at',
            ]);
        });
    }
};
