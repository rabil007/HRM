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
        Schema::create('employee_document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_document_id')->constrained('employee_documents')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('file_path', 500);
            $table->string('original_filename', 255)->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->unsignedBigInteger('replaced_by')->nullable();
            $table->timestamps();

            $table->unique(['employee_document_id', 'version'], 'uq_employee_document_version');
            $table->index(['company_id', 'employee_id'], 'idx_doc_versions_company_employee');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_document_versions');
    }
};
