<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_signing_flows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies', indexName: 'doc_sign_flow_comp_fk')
                ->cascadeOnDelete();
            $table->foreignId('document_instance_id')
                ->constrained('document_instances', indexName: 'doc_sign_flow_inst_fk')
                ->restrictOnDelete();
            $table->foreignId('document_signing_preset_id')
                ->nullable()
                ->constrained('document_signing_presets', indexName: 'doc_sign_flow_preset_fk')
                ->nullOnDelete();
            $table->foreignId('starting_document_instance_version_id')
                ->constrained('document_instance_versions', indexName: 'doc_sign_flow_start_ver_fk')
                ->restrictOnDelete();
            $table->string('preset_name_snapshot', 150);
            $table->json('routing_definition_snapshot');
            $table->string('status', 32)->default('active');
            $table->unsignedInteger('current_step_sequence')->nullable();
            $table->foreignId('started_by')
                ->constrained('users', indexName: 'doc_sign_flow_started_fk')
                ->restrictOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('blocked_at')->nullable();
            $table->text('blocked_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')
                ->nullable()
                ->constrained('users', indexName: 'doc_sign_flow_cancel_fk')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status'], 'doc_sign_flow_comp_stat_idx');
            $table->index(['document_instance_id', 'status'], 'doc_sign_flow_inst_stat_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_signing_flows');
    }
};
