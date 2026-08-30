<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_recipient_requests', function (Blueprint $table) {
            $table->foreignId('document_signing_flow_id')
                ->nullable()
                ->after('document_workflow_request_id')
                ->constrained('document_signing_flows', indexName: 'doc_rr_sign_flow_fk')
                ->nullOnDelete();
            $table->unsignedInteger('signing_step_sequence')
                ->nullable()
                ->after('document_signing_flow_id');

            $table->index(
                ['document_signing_flow_id', 'signing_step_sequence'],
                'doc_rr_sign_flow_step_idx',
            );
            $table->index(
                ['company_id', 'document_signing_flow_id', 'status'],
                'doc_rr_comp_sign_flow_stat_idx',
            );
        });
    }

    public function down(): void
    {
        // Drop the foreign key first so MySQL will allow removing its supporting indexes.
        // UP used constrained(..., indexName: 'doc_rr_sign_flow_fk'); dropping by column
        // array would target the conventional *_foreign name and fail on MySQL.
        // SQLite cannot drop foreign keys by name and must use the column list.
        Schema::table('document_recipient_requests', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $table->dropForeign(['document_signing_flow_id']);
            } else {
                $table->dropForeign('doc_rr_sign_flow_fk');
            }
        });

        Schema::table('document_recipient_requests', function (Blueprint $table) {
            $table->dropIndex('doc_rr_comp_sign_flow_stat_idx');
            $table->dropIndex('doc_rr_sign_flow_step_idx');
        });

        Schema::table('document_recipient_requests', function (Blueprint $table) {
            $table->dropColumn([
                'document_signing_flow_id',
                'signing_step_sequence',
            ]);
        });
    }
};
