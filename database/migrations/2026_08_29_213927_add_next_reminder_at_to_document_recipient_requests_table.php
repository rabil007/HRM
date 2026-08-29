<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_recipient_requests', function (Blueprint $table) {
            $table->timestamp('next_reminder_at')->nullable()->after('reminder_policy_snapshot');

            $table->index(['status', 'next_reminder_at'], 'doc_rec_req_stat_next_rem_idx');
            $table->index(
                ['company_id', 'status', 'next_reminder_at'],
                'doc_rec_req_comp_stat_next_rem_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('document_recipient_requests', function (Blueprint $table) {
            $table->dropIndex('doc_rec_req_stat_next_rem_idx');
            $table->dropIndex('doc_rec_req_comp_stat_next_rem_idx');
            $table->dropColumn('next_reminder_at');
        });
    }
};
