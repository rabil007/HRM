<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained('document_types')->restrictOnDelete();
            $table->boolean('required_for_all')->default(false);
            $table->boolean('require_issue_date')->default(false);
            $table->boolean('require_expiry_date')->default(false);
            $table->boolean('require_document_number')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'document_type_id'], 'uq_document_requirements_company_type');
            $table->index(['company_id', 'is_active'], 'idx_document_requirements_company_active');
            $table->index('document_type_id', 'idx_document_requirements_type');
        });

        Schema::create('document_requirement_department', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_requirement_id')->constrained('document_requirements')->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();

            $table->unique(['document_requirement_id', 'department_id'], 'uq_doc_req_department');
            $table->index('department_id', 'idx_doc_req_department');
        });

        Schema::create('document_requirement_position', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_requirement_id')->constrained('document_requirements')->cascadeOnDelete();
            $table->foreignId('position_id')->constrained('positions')->cascadeOnDelete();

            $table->unique(['document_requirement_id', 'position_id'], 'uq_doc_req_position');
            $table->index('position_id', 'idx_doc_req_position');
        });

        Schema::create('document_requirement_rank', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_requirement_id')->constrained('document_requirements')->cascadeOnDelete();
            $table->foreignId('rank_id')->constrained('ranks')->cascadeOnDelete();

            $table->unique(['document_requirement_id', 'rank_id'], 'uq_doc_req_rank');
            $table->index('rank_id', 'idx_doc_req_rank');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_requirement_rank');
        Schema::dropIfExists('document_requirement_position');
        Schema::dropIfExists('document_requirement_department');
        Schema::dropIfExists('document_requirements');
    }
};
