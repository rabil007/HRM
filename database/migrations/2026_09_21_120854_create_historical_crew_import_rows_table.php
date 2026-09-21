<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('historical_crew_import_rows')) {
            return;
        }

        Schema::create('historical_crew_import_rows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('historical_crew_import_batch_id');
            $table->unsignedInteger('row_number');
            $table->string('employee_no', 64)->nullable();
            $table->string('employee_name', 255)->nullable();
            $table->string('vessel_name', 255)->nullable();
            $table->string('rank_name', 255)->nullable();
            $table->string('status', 40);
            $table->unsignedBigInteger('crew_assignment_id')->nullable();
            $table->string('assignment_no', 32)->nullable();
            $table->json('warnings')->nullable();
            $table->json('errors')->nullable();
            $table->timestamps();

            $table->foreign('historical_crew_import_batch_id', 'fk_hist_crew_rows_batch')
                ->references('id')
                ->on('historical_crew_import_batches')
                ->cascadeOnDelete();

            $table->foreign('crew_assignment_id', 'fk_hist_crew_rows_assignment')
                ->references('id')
                ->on('crew_assignments')
                ->nullOnDelete();

            $table->unique(['historical_crew_import_batch_id', 'row_number'], 'uq_hist_crew_import_rows_batch_row');
            $table->index(['historical_crew_import_batch_id', 'status'], 'idx_hist_crew_import_rows_batch_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historical_crew_import_rows');
    }
};
