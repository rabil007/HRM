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
        Schema::create('document_workflow_presets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies', indexName: 'doc_wf_preset_comp_fk')
                ->cascadeOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('status', 32)->default('active');
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users', indexName: 'doc_wf_preset_by_fk')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'name'], 'uq_doc_wf_preset_company_name');
            $table->index(['company_id', 'status'], 'doc_wf_preset_comp_stat_idx');
        });

        Schema::create('document_workflow_preset_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies', indexName: 'doc_wf_preset_stg_comp_fk')
                ->cascadeOnDelete();
            $table->foreignId('document_workflow_preset_id')
                ->constrained('document_workflow_presets', indexName: 'doc_wf_preset_stg_preset_fk')
                ->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('action', 32);
            $table->string('completion_rule', 16);
            $table->timestamps();

            $table->unique(['document_workflow_preset_id', 'sequence'], 'doc_wf_preset_stg_seq_unique');
        });

        Schema::create('document_workflow_preset_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies', indexName: 'doc_wf_preset_tgt_comp_fk')
                ->cascadeOnDelete();
            $table->foreignId('document_workflow_preset_stage_id')
                ->constrained('document_workflow_preset_stages', indexName: 'doc_wf_preset_tgt_stg_fk')
                ->cascadeOnDelete();
            $table->string('target_type', 32);
            $table->foreignId('target_user_id')
                ->nullable()
                ->constrained('users', indexName: 'doc_wf_preset_tgt_user_fk')
                ->nullOnDelete();
            $table->unsignedBigInteger('target_role_id')->nullable();
            $table->timestamps();

            $table->index(['document_workflow_preset_stage_id', 'target_type'], 'doc_wf_preset_tgt_stg_type_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_workflow_preset_targets');
        Schema::dropIfExists('document_workflow_preset_stages');
        Schema::dropIfExists('document_workflow_presets');
    }
};
