<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_document_signature_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_document_id')->constrained('employee_documents')->cascadeOnDelete();
            $table->string('document_type_key', 64);
            $table->string('token', 64)->unique();
            $table->string('status', 32)->default('awaiting_signature');
            $table->string('signed_name')->nullable();
            $table->string('signature_image_path')->nullable();
            $table->string('signed_pdf_path')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('submitted_ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('batch_id')->nullable()->constrained('bulk_document_email_batches')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['company_id', 'document_type_key', 'status'], 'bulk_doc_sig_req_company_type_status_idx');
            $table->index(['employee_id', 'document_type_key'], 'bulk_doc_sig_req_employee_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_document_signature_requests');
    }
};
