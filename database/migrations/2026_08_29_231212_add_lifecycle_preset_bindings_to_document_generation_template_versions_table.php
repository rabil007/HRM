<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_generation_template_versions', function (Blueprint $table) {
            $table->foreignId('document_workflow_preset_id')
                ->nullable()
                ->after('signature_placement_config')
                ->constrained('document_workflow_presets', indexName: 'doc_gen_tv_wf_preset_fk')
                ->restrictOnDelete();

            $table->foreignId('document_signing_preset_id')
                ->nullable()
                ->after('document_workflow_preset_id')
                ->constrained('document_signing_presets', indexName: 'doc_gen_tv_sign_preset_fk')
                ->restrictOnDelete();

            $table->index(
                ['company_id', 'document_workflow_preset_id'],
                'doc_gen_tv_comp_wf_preset_idx',
            );
            $table->index(
                ['company_id', 'document_signing_preset_id'],
                'doc_gen_tv_comp_sign_preset_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('document_generation_template_versions', function (Blueprint $table) {
            $table->dropIndex('doc_gen_tv_comp_wf_preset_idx');
            $table->dropIndex('doc_gen_tv_comp_sign_preset_idx');
            $table->dropConstrainedForeignId('document_workflow_preset_id');
            $table->dropConstrainedForeignId('document_signing_preset_id');
        });
    }
};
