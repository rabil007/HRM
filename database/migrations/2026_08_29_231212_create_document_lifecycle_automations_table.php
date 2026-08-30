<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_lifecycle_automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies', indexName: 'doc_life_auto_comp_fk')
                ->cascadeOnDelete();
            $table->foreignId('document_instance_id')
                ->constrained('document_instances', indexName: 'doc_life_auto_inst_fk')
                ->restrictOnDelete();
            $table->foreignId('source_document_instance_version_id')
                ->constrained('document_instance_versions', indexName: 'doc_life_auto_src_ver_fk')
                ->restrictOnDelete();
            $table->foreignId('document_generation_template_version_id')
                ->constrained('document_generation_template_versions', indexName: 'doc_life_auto_tv_fk')
                ->restrictOnDelete();

            $table->foreignId('document_workflow_preset_id')
                ->nullable()
                ->constrained('document_workflow_presets', indexName: 'doc_life_auto_wf_preset_fk')
                ->nullOnDelete();
            $table->foreignId('document_signing_preset_id')
                ->nullable()
                ->constrained('document_signing_presets', indexName: 'doc_life_auto_sign_preset_fk')
                ->nullOnDelete();

            $table->foreignId('document_workflow_request_id')
                ->nullable()
                ->constrained('document_workflow_requests', indexName: 'doc_life_auto_wf_req_fk')
                ->nullOnDelete();
            $table->foreignId('document_signing_flow_id')
                ->nullable()
                ->constrained('document_signing_flows', indexName: 'doc_life_auto_sign_flow_fk')
                ->nullOnDelete();

            $table->json('policy_snapshot');
            $table->string('status', 32);
            $table->string('stage', 32)->nullable();
            $table->string('blocked_code', 64)->nullable();
            $table->text('blocked_message')->nullable();

            $table->foreignId('initiated_by')
                ->nullable()
                ->constrained('users', indexName: 'doc_life_auto_init_fk')
                ->nullOnDelete();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique('document_instance_id', 'doc_life_auto_inst_uq');
            $table->index(['company_id', 'status'], 'doc_life_auto_comp_stat_idx');
            $table->index('document_workflow_request_id', 'doc_life_auto_wf_req_idx');
            $table->index('document_signing_flow_id', 'doc_life_auto_sign_flow_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_lifecycle_automations');
    }
};
