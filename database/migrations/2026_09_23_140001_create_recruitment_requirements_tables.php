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
        Schema::create('recruitment_requirement_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies', 'id', 'fk_req_seq_company')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('current_number')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'year'], 'uq_recruitment_seq_company_year');
        });

        Schema::create('recruitment_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies', 'id', 'fk_req_company')->cascadeOnDelete();
            $table->string('requirement_number', 30);
            $table->foreignId('client_id')->constrained('clients', 'id', 'fk_req_client')->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects', 'id', 'fk_req_project')->nullOnDelete();
            $table->string('client_reference_number', 100)->nullable();
            $table->date('request_received_date');
            $table->date('required_by_date');
            $table->string('location', 200)->nullable();
            $table->string('priority', 20)->default('normal');
            $table->foreignId('assigned_to')->nullable()->constrained('users', 'id', 'fk_req_assigned_to')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('repeated_from_id')->nullable()->constrained('recruitment_requirements', 'id', 'fk_req_repeated_from')->nullOnDelete();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users', 'id', 'fk_req_created_by')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users', 'id', 'fk_req_updated_by')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'requirement_number'], 'uq_recruitment_req_company_number');
            $table->index(['company_id', 'status'], 'idx_recruitment_req_company_status');
            $table->index(['company_id', 'client_id', 'project_id'], 'idx_recruitment_req_company_client_project');
            $table->index(['company_id', 'required_by_date'], 'idx_recruitment_req_company_required_by');
            $table->index(['company_id', 'assigned_to'], 'idx_recruitment_req_company_assigned_to');
            $table->index(['company_id', 'repeated_from_id'], 'idx_recruitment_req_company_repeated_from');
        });

        Schema::create('recruitment_requirement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies', 'id', 'fk_req_lines_company')->cascadeOnDelete();
            $table->foreignId('recruitment_requirement_id')->constrained('recruitment_requirements', 'id', 'fk_req_lines_requirement')->cascadeOnDelete();
            $table->foreignId('position_id')->constrained('positions', 'id', 'fk_req_lines_position')->restrictOnDelete();
            $table->unsignedInteger('required_headcount');
            $table->text('line_notes')->nullable();
            $table->string('status', 20)->default('open');
            $table->timestamps();

            $table->unique(['recruitment_requirement_id', 'position_id'], 'uq_recruitment_req_lines_req_position');
            $table->index(['company_id', 'position_id'], 'idx_recruitment_req_lines_company_position');
            $table->index(['company_id', 'recruitment_requirement_id'], 'idx_recruitment_req_lines_company_req');
        });

        Schema::create('recruitment_requirement_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies', 'id', 'fk_req_att_company')->cascadeOnDelete();
            $table->foreignId('recruitment_requirement_id')->constrained('recruitment_requirements', 'id', 'fk_req_att_requirement')->cascadeOnDelete();
            $table->string('file_path', 500);
            $table->string('original_file_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size_bytes');
            $table->string('file_checksum', 64)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users', 'id', 'fk_req_att_uploaded_by')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'recruitment_requirement_id'], 'idx_recruitment_att_company_req');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recruitment_requirement_attachments');
        Schema::dropIfExists('recruitment_requirement_lines');
        Schema::dropIfExists('recruitment_requirements');
        Schema::dropIfExists('recruitment_requirement_sequences');
    }
};
