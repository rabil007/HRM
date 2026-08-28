<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_recipient_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies', indexName: 'doc_rec_req_comp_fk')
                ->cascadeOnDelete();
            $table->foreignId('document_instance_id')
                ->constrained('document_instances', indexName: 'doc_rec_req_inst_fk')
                ->restrictOnDelete();
            $table->foreignId('source_document_instance_version_id')
                ->constrained('document_instance_versions', indexName: 'doc_rec_req_src_ver_fk')
                ->restrictOnDelete();
            $table->foreignId('result_document_instance_version_id')
                ->nullable()
                ->constrained('document_instance_versions', indexName: 'doc_rec_req_res_ver_fk')
                ->nullOnDelete();
            $table->foreignId('document_workflow_request_id')
                ->nullable()
                ->constrained('document_workflow_requests', indexName: 'doc_rec_req_wf_fk')
                ->nullOnDelete();

            $table->string('action', 32);
            $table->string('recipient_type', 32);

            $table->foreignId('employee_id')
                ->constrained('employees', indexName: 'doc_rec_req_emp_fk')
                ->restrictOnDelete();
            $table->foreignId('recipient_user_id')
                ->nullable()
                ->constrained('users', indexName: 'doc_rec_req_user_fk')
                ->nullOnDelete();
            $table->string('recipient_name_snapshot', 255);

            $table->string('status', 32);

            $table->string('token_hash', 64)->unique('doc_rec_req_token_hash_uq');
            $table->timestamp('expires_at');

            $table->foreignId('requested_by')
                ->nullable()
                ->constrained('users', indexName: 'doc_rec_req_req_by_fk')
                ->nullOnDelete();
            $table->timestamp('requested_at');

            $table->timestamp('first_viewed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')
                ->nullable()
                ->constrained('users', indexName: 'doc_rec_req_cancel_by_fk')
                ->nullOnDelete();

            $table->string('signed_name', 255)->nullable();
            $table->string('signature_image_path', 500)->nullable();

            $table->timestamp('consent_at')->nullable();

            $table->string('submitted_ip', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->string('source_checksum_sha256', 64);
            $table->string('result_checksum_sha256', 64)->nullable();

            $table->text('acknowledgement_text_snapshot')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status'], 'doc_rec_req_comp_stat_idx');
            $table->index(['document_instance_id', 'source_document_instance_version_id'], 'doc_rec_req_inst_src_idx');
            $table->index(['employee_id', 'status'], 'doc_rec_req_emp_stat_idx');
        });

        Schema::create('document_recipient_request_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies', indexName: 'doc_rec_evt_comp_fk')
                ->cascadeOnDelete();
            $table->foreignId('document_recipient_request_id')
                ->constrained('document_recipient_requests', indexName: 'doc_rec_evt_req_fk')
                ->cascadeOnDelete();

            $table->string('event', 64);
            $table->foreignId('actor_user_id')
                ->nullable()
                ->constrained('users', indexName: 'doc_rec_evt_actor_fk')
                ->nullOnDelete();
            $table->timestamp('occurred_at');

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['document_recipient_request_id', 'occurred_at'], 'doc_rec_evt_req_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_recipient_request_events');
        Schema::dropIfExists('document_recipient_requests');
    }
};
