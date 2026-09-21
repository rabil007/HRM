<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('crew_assignments', 'historical_import_batch_id')) {
            return;
        }

        Schema::table('crew_assignments', function (Blueprint $table) {
            $table->unsignedBigInteger('historical_import_batch_id')
                ->nullable()
                ->after('source');

            $table->foreign('historical_import_batch_id', 'fk_crew_assignment_hist_batch')
                ->references('id')
                ->on('historical_crew_import_batches')
                ->nullOnDelete();

            $table->index(['company_id', 'historical_import_batch_id'], 'idx_crew_assignments_company_hist_batch');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('crew_assignments', 'historical_import_batch_id')) {
            return;
        }

        Schema::table('crew_assignments', function (Blueprint $table) {
            $table->dropForeign('fk_crew_assignment_hist_batch');
            $table->dropIndex('idx_crew_assignments_company_hist_batch');
            $table->dropColumn('historical_import_batch_id');
        });
    }
};
