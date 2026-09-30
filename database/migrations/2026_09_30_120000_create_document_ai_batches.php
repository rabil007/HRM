<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Recover from a prior MySQL deploy that created tables then failed on
        // an auto-generated unique index name longer than 64 characters.
        Schema::dropIfExists('document_ai_batch_items');
        Schema::dropIfExists('document_ai_batches');

        Schema::create('document_ai_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('status', 30)->default('pending')->index();
            $table->unsignedSmallInteger('total_items')->default(0);
            $table->unsignedSmallInteger('completed_items')->default(0);
            $table->unsignedSmallInteger('failed_items')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->index(['company_id', 'user_id', 'employee_id']);
        });

        Schema::create('document_ai_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_ai_batch_id')->constrained('document_ai_batches')->cascadeOnDelete();
            $table->uuid('client_draft_id');
            $table->string('status', 20)->default('queued')->index();
            $table->string('temporary_file_reference');
            $table->string('original_filename');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->string('detected_document_type', 30)->nullable();
            $table->decimal('overall_confidence', 5, 4)->nullable();
            $table->json('normalized_result_json')->nullable();
            $table->string('safe_error_code', 60)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            // MySQL identifier limit is 64 chars; the default composite name exceeds it.
            $table->unique(
                ['document_ai_batch_id', 'client_draft_id'],
                'dai_batch_items_batch_draft_uidx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_ai_batch_items');
        Schema::dropIfExists('document_ai_batches');
    }
};
