<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_template_layout_validation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies', indexName: 'doc_tmpl_lay_val_comp_fk')
                ->cascadeOnDelete();
            $table->foreignId('document_generation_template_id')
                ->constrained('document_generation_templates', indexName: 'doc_tmpl_lay_val_tmpl_fk')
                ->cascadeOnDelete();
            $table->foreignId('document_generation_template_version_id')
                ->constrained('document_generation_template_versions', indexName: 'doc_tmpl_lay_val_ver_fk')
                ->cascadeOnDelete();
            $table->foreignId('requested_by')
                ->nullable()
                ->constrained('users', indexName: 'doc_tmpl_lay_val_req_fk')
                ->nullOnDelete();
            $table->string('mode', 20);
            $table->foreignId('employee_id')
                ->nullable()
                ->constrained('employees', indexName: 'doc_tmpl_lay_val_emp_fk')
                ->nullOnDelete();
            $table->boolean('authoritative')->default(false);
            $table->char('fingerprint', 64);
            $table->string('status', 20);
            $table->json('issues')->nullable();
            $table->json('effective_font_sizes')->nullable();
            $table->json('placement_config')->nullable();
            $table->json('validated_with')->nullable();
            $table->string('reference', 40)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('company_id', 'doc_tmpl_lay_val_comp_idx');
            $table->index('document_generation_template_id', 'doc_tmpl_lay_val_tmpl_idx');
            $table->index('document_generation_template_version_id', 'doc_tmpl_lay_val_ver_idx');
            $table->index('status', 'doc_tmpl_lay_val_stat_idx');
            $table->index('fingerprint', 'doc_tmpl_lay_val_fp_idx');
            $table->index(
                ['company_id', 'document_generation_template_version_id', 'fingerprint', 'mode', 'authoritative'],
                'doc_tmpl_lay_val_reuse_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_template_layout_validation_runs');
    }
};
