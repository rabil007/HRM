<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_recipient_requests', function (Blueprint $table) {
            $table->string('recipient_role', 32)
                ->default('subject')
                ->after('recipient_type');

            $table->index(
                ['company_id', 'recipient_user_id', 'status'],
                'doc_rec_req_comp_user_stat_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('document_recipient_requests', function (Blueprint $table) {
            $table->dropIndex('doc_rec_req_comp_user_stat_idx');
            $table->dropColumn('recipient_role');
        });
    }
};
