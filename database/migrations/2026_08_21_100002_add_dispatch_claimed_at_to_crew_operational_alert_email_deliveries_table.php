<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crew_operational_alert_email_deliveries', function (Blueprint $table) {
            $table->timestamp('dispatch_claimed_at')
                ->nullable()
                ->after('dispatched_at');

            $table->index(
                ['company_id', 'status', 'dispatched_at', 'dispatch_claimed_at'],
                'crew_alert_email_comp_stat_disp_claim_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('crew_operational_alert_email_deliveries', function (Blueprint $table) {
            $table->dropIndex('crew_alert_email_comp_stat_disp_claim_idx');
            $table->dropColumn('dispatch_claimed_at');
        });
    }
};
