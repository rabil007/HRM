<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_recipient_request_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies', indexName: 'doc_rr_delivery_company_fk')
                ->cascadeOnDelete();
            $table->foreignId('document_recipient_request_id')
                ->constrained('document_recipient_requests', indexName: 'doc_rr_delivery_request_fk')
                ->cascadeOnDelete();
            $table->string('channel', 32);
            $table->string('purpose', 32);
            $table->unsignedInteger('delivery_sequence');
            $table->string('destination_snapshot')->nullable();
            $table->string('template_slug', 100)->nullable();
            $table->string('subject_snapshot', 255)->nullable();
            $table->string('access_token_hash', 64)->nullable();
            $table->string('status', 32);
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('failure_category', 64)->nullable();
            $table->foreignId('requested_by')
                ->nullable()
                ->constrained('users', indexName: 'doc_rr_delivery_requested_by_fk')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['document_recipient_request_id', 'channel', 'delivery_sequence'],
                'doc_rr_delivery_req_chan_seq_uq',
            );
            $table->unique('access_token_hash', 'doc_rr_delivery_access_token_uq');
            $table->index(
                ['company_id', 'document_recipient_request_id', 'status'],
                'doc_rr_delivery_comp_req_stat_idx',
            );
            $table->index(
                ['status', 'dispatched_at', 'claimed_at'],
                'doc_rr_delivery_dispatch_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_recipient_request_deliveries');
    }
};
