<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_recipient_request_deliveries', function (Blueprint $table) {
            $table->string('automation_key', 100)->nullable()->after('purpose');
            $table->timestamp('scheduled_for')->nullable()->after('automation_key');

            $table->unique(
                ['document_recipient_request_id', 'channel', 'automation_key'],
                'doc_rr_delivery_req_chan_auto_uq',
            );
        });
    }

    public function down(): void
    {
        Schema::table('document_recipient_request_deliveries', function (Blueprint $table) {
            $table->dropUnique('doc_rr_delivery_req_chan_auto_uq');
            $table->dropColumn(['automation_key', 'scheduled_for']);
        });
    }
};
