<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_document_generation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('document_type_key', 64);
            $table->json('filters')->nullable();
            $table->string('status', 32)->default('queued');
            $table->unsignedInteger('total_targeted')->default(0);
            $table->unsignedInteger('generated_count')->default(0);
            $table->unsignedInteger('replaced_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->string('correlation_id', 64)->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });

        Schema::create('bulk_document_email_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('document_type_key', 64);
            $table->foreignId('email_template_id')->nullable()->constrained('email_templates')->nullOnDelete();
            $table->string('subject', 500);
            $table->unsignedInteger('total_selected')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('skipped_no_email_count')->default(0);
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
        });

        Schema::create('bulk_document_email_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('bulk_document_email_batches')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_document_id')->nullable()->constrained('employee_documents')->nullOnDelete();
            $table->string('recipient_email', 200)->nullable();
            $table->string('status', 32);
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_document_email_sends');
        Schema::dropIfExists('bulk_document_email_batches');
        Schema::dropIfExists('bulk_document_generation_runs');
    }
};
