<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bulk_document_signature_repair_runs')) {
            return;
        }

        Schema::create('bulk_document_signature_repair_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('document_type_key', 64);
            $table->string('status', 32)->default('queued');
            $table->unsignedInteger('total_count')->default(0);
            $table->unsignedInteger('repaired_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'document_type_key', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_document_signature_repair_runs');
    }
};
