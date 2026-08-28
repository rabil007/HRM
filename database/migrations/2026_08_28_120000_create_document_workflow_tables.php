<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('document_workflow_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies', indexName: 'doc_wf_req_comp_fk')
                ->cascadeOnDelete();
            $table->foreignId('document_instance_id')
                ->constrained('document_instances', indexName: 'doc_wf_req_inst_fk')
                ->restrictOnDelete();
            $table->foreignId('document_instance_version_id')
                ->constrained('document_instance_versions', indexName: 'doc_wf_req_ver_fk')
                ->restrictOnDelete();
            $table->string('status', 32)->default('pending');
            $table->foreignId('requested_by')
                ->constrained('users', indexName: 'doc_wf_req_by_fk')
                ->restrictOnDelete();
            $table->string('requester_name_snapshot', 255);
            $table->timestamp('requested_at');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('cancelled_by')
                ->nullable()
                ->constrained('users', indexName: 'doc_wf_req_cancel_fk')
                ->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status'], 'doc_wf_req_comp_stat_idx');
            $table->index(['document_instance_id', 'document_instance_version_id'], 'doc_wf_req_inst_ver_idx');
        });

        Schema::create('document_workflow_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies', indexName: 'doc_wf_stg_comp_fk')
                ->cascadeOnDelete();
            $table->foreignId('document_workflow_request_id')
                ->constrained('document_workflow_requests', indexName: 'doc_wf_stg_req_fk')
                ->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('action', 32);
            $table->string('completion_rule', 16);
            $table->string('status', 32)->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['document_workflow_request_id', 'sequence'], 'doc_wf_stg_req_seq_unique');
            $table->index(['document_workflow_request_id', 'status'], 'doc_wf_stg_req_stat_idx');
        });

        Schema::create('document_workflow_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies', indexName: 'doc_wf_task_comp_fk')
                ->cascadeOnDelete();
            $table->foreignId('document_workflow_stage_id')
                ->constrained('document_workflow_stages', indexName: 'doc_wf_task_stg_fk')
                ->restrictOnDelete();
            $table->foreignId('assignee_user_id')
                ->nullable()
                ->constrained('users', indexName: 'doc_wf_task_user_fk')
                ->restrictOnDelete();
            $table->string('assignee_name_snapshot', 255);
            $table->string('status', 32)->default('pending');
            $table->foreignId('decided_by')
                ->nullable()
                ->constrained('users', indexName: 'doc_wf_task_dec_fk')
                ->nullOnDelete();
            $table->string('decision_actor_name_snapshot', 255)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->timestamps();

            $table->unique(['document_workflow_stage_id', 'assignee_user_id'], 'doc_wf_task_stg_user_unique');
            $table->index(['assignee_user_id', 'status'], 'doc_wf_task_user_stat_idx');
            $table->index(['document_workflow_stage_id', 'status'], 'doc_wf_task_stg_stat_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_workflow_tasks');
        Schema::dropIfExists('document_workflow_stages');
        Schema::dropIfExists('document_workflow_requests');
    }
};
