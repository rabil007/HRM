<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 Offer/JOL. Leaves legacy job_offers untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recruitment_candidate_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('recruitment_candidate_id')->constrained('recruitment_candidates')->cascadeOnDelete();
            $table->unsignedInteger('revision_number')->default(1);
            $table->boolean('is_current')->default(true);
            $table->foreignId('supersedes_offer_id')
                ->nullable()
                ->constrained('recruitment_candidate_offers')
                ->nullOnDelete();
            $table->string('status', 30)->default('draft');
            $table->decimal('salary_amount', 12, 2);
            $table->string('salary_currency_code', 3);
            $table->date('proposed_joining_date');
            $table->date('offer_date');
            $table->date('expiry_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->text('revision_reason')->nullable();
            $table->string('offer_document_path')->nullable();
            $table->string('offer_document_original_file_name')->nullable();
            $table->string('offer_document_mime_type', 120)->nullable();
            $table->unsignedBigInteger('offer_document_file_size_bytes')->nullable();
            $table->string('offer_document_file_checksum', 64)->nullable();
            $table->string('acceptance_document_path')->nullable();
            $table->string('acceptance_document_original_file_name')->nullable();
            $table->string('acceptance_document_mime_type', 120)->nullable();
            $table->unsignedBigInteger('acceptance_document_file_size_bytes')->nullable();
            $table->string('acceptance_document_file_checksum', 64)->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status'], 'idx_rec_offer_company_status');
            $table->index(['recruitment_candidate_id', 'is_current'], 'idx_rec_offer_candidate_current');
            $table->index(['recruitment_candidate_id', 'revision_number'], 'idx_rec_offer_candidate_revision');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_candidate_offers');
    }
};
