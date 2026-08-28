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
        // 1. document_instances table
        Schema::create('document_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies', indexName: 'doc_inst_comp_fk')
                ->cascadeOnDelete();
            $table->foreignId('employee_id')
                ->nullable()
                ->constrained('employees', indexName: 'doc_inst_emp_fk')
                ->nullOnDelete();
            $table->string('employee_name_snapshot', 255);
            $table->string('employee_no_snapshot', 64)->nullable();

            $table->foreignId('document_generation_template_id')
                ->constrained('document_generation_templates', indexName: 'doc_inst_tmpl_fk')
                ->restrictOnDelete();
            $table->foreignId('document_generation_template_version_id')
                ->constrained('document_generation_template_versions', indexName: 'doc_inst_tmpl_ver_fk')
                ->restrictOnDelete();
            $table->foreignId('document_type_id')
                ->nullable()
                ->constrained('document_types', indexName: 'doc_inst_doc_type_fk')
                ->nullOnDelete();

            $table->unsignedBigInteger('document_generation_run_id')->nullable();
            $table->foreignId('employee_document_id')
                ->nullable()
                ->constrained('employee_documents', indexName: 'doc_inst_emp_doc_fk')
                ->nullOnDelete();

            $table->string('template_name_snapshot', 200);
            $table->unsignedInteger('template_version_number');
            $table->string('title_snapshot', 255);

            $table->string('status', 32)->default('generated');
            $table->unsignedBigInteger('current_version_id')->nullable();

            $table->foreignId('generated_by')
                ->nullable()
                ->constrained('users', indexName: 'doc_inst_gen_by_fk')
                ->nullOnDelete();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->index(['company_id', 'document_generation_template_version_id'], 'doc_inst_comp_tmpl_ver_idx');
            $table->index(['employee_id', 'document_generation_template_version_id'], 'doc_inst_emp_tmpl_ver_idx');
            $table->index('employee_document_id', 'doc_inst_emp_doc_idx');
            $table->index('document_generation_run_id', 'doc_inst_run_idx');
        });

        // 2. document_instance_versions table
        Schema::create('document_instance_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies', indexName: 'doc_inst_ver_comp_fk')
                ->cascadeOnDelete();
            $table->foreignId('document_instance_id')
                ->constrained('document_instances', indexName: 'doc_inst_ver_inst_fk')
                ->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->string('stage', 32)->default('generated');

            $table->string('file_path', 500);
            $table->string('original_filename', 255);
            $table->string('mime_type', 100)->default('application/pdf');
            $table->unsignedBigInteger('size_bytes');
            $table->string('checksum', 64);

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users', indexName: 'doc_inst_ver_creator_fk')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(['document_instance_id', 'version'], 'doc_inst_ver_unique');
            $table->index('company_id', 'doc_inst_ver_comp_idx');
        });

        // Add foreign key for current_version_id on document_instances
        Schema::table('document_instances', function (Blueprint $table) {
            $table->foreign('current_version_id', 'doc_inst_curr_ver_fk')
                ->references('id')
                ->on('document_instance_versions')
                ->nullOnDelete();
        });

        // 3. document_generation_runs table
        Schema::create('document_generation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies', indexName: 'doc_gen_run_comp_fk')
                ->cascadeOnDelete();

            $table->foreignId('document_generation_template_id')
                ->constrained('document_generation_templates', indexName: 'doc_gen_run_tmpl_fk')
                ->restrictOnDelete();
            $table->foreignId('document_generation_template_version_id')
                ->constrained('document_generation_template_versions', indexName: 'doc_gen_run_tmpl_ver_fk')
                ->restrictOnDelete();

            $table->json('filters')->nullable();
            $table->string('status', 32)->default('queued');

            $table->unsignedInteger('total_targeted')->default(0);
            $table->unsignedInteger('generated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);

            $table->string('correlation_id', 64);

            $table->foreignId('triggered_by')
                ->nullable()
                ->constrained('users', indexName: 'doc_gen_run_user_fk')
                ->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status'], 'doc_gen_run_comp_stat_idx');
            $table->index('document_generation_template_version_id', 'doc_gen_run_tmpl_ver_idx');
        });

        // Add foreign key for document_generation_run_id on document_instances
        Schema::table('document_instances', function (Blueprint $table) {
            $table->foreign('document_generation_run_id', 'doc_inst_run_fk')
                ->references('id')
                ->on('document_generation_runs')
                ->nullOnDelete();
        });

        // 4. document_generation_run_items table
        Schema::create('document_generation_run_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')
                ->constrained('companies', indexName: 'doc_gen_item_comp_fk')
                ->cascadeOnDelete();
            $table->foreignId('document_generation_run_id')
                ->constrained('document_generation_runs', indexName: 'doc_gen_item_run_fk')
                ->cascadeOnDelete();
            $table->foreignId('employee_id')
                ->constrained('employees', indexName: 'doc_gen_item_emp_fk')
                ->cascadeOnDelete();

            $table->string('status', 32)->default('pending');

            $table->foreignId('document_instance_id')
                ->nullable()
                ->constrained('document_instances', indexName: 'doc_gen_item_inst_fk')
                ->nullOnDelete();

            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 500)->nullable();

            $table->timestamps();

            $table->unique(['document_generation_run_id', 'employee_id'], 'doc_gen_item_run_emp_unique');
            $table->index(['company_id', 'status'], 'doc_gen_item_comp_stat_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_generation_run_items');

        Schema::table('document_instances', function (Blueprint $table) {
            $table->dropForeign('doc_inst_run_fk');
            $table->dropForeign('doc_inst_curr_ver_fk');
        });

        Schema::dropIfExists('document_generation_runs');
        Schema::dropIfExists('document_instance_versions');
        Schema::dropIfExists('document_instances');
    }
};
