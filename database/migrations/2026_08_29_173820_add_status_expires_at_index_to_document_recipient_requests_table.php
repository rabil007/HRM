<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_recipient_requests', function (Blueprint $table) {
            $table->index(['status', 'expires_at'], 'doc_rec_req_stat_exp_idx');
            $table->index(['company_id', 'status', 'expires_at'], 'doc_rec_req_comp_stat_exp_idx');
        });
    }

    public function down(): void
    {
        Schema::table('document_recipient_requests', function (Blueprint $table) {
            $table->dropIndex('doc_rec_req_stat_exp_idx');
            $table->dropIndex('doc_rec_req_comp_stat_exp_idx');
        });
    }
};
