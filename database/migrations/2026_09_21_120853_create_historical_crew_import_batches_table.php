<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('historical_crew_import_batches')) {
            return;
        }

        Schema::create('historical_crew_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('batch_no', 32);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_filename', 255);
            $table->string('status', 40);
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('ready_rows')->default(0);
            $table->unsignedInteger('warning_rows')->default(0);
            $table->unsignedInteger('blocked_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->string('idempotency_key', 64)->nullable();
            $table->string('workbook_hash', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_progress_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'batch_no'], 'uq_hist_crew_batches_company_batch_no');
            $table->unique(['company_id', 'idempotency_key'], 'uq_hist_crew_batches_company_idem');
            $table->index(['company_id', 'created_at'], 'idx_hist_crew_batches_company_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historical_crew_import_batches');
    }
};
