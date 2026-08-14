<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crew_timesheet_segments')) {
            return;
        }

        Schema::table('crew_timesheet_segments', function (Blueprint $table) {
            if (Schema::hasColumn('crew_timesheet_segments', 'vessel_id')) {
                $table->dropForeign(['vessel_id']);
                $table->dropColumn('vessel_id');
            }

            if (Schema::hasColumn('crew_timesheet_segments', 'client_id')) {
                $table->dropForeign(['client_id']);
                $table->dropColumn('client_id');
            }

            if (Schema::hasColumn('crew_timesheet_segments', 'rank_id')) {
                $table->dropForeign(['rank_id']);
                $table->dropColumn('rank_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('crew_timesheet_segments')) {
            return;
        }

        Schema::table('crew_timesheet_segments', function (Blueprint $table) {
            // Restore nullable columns. Note: Previously deleted manual/import values cannot be reconstructed.
            if (! Schema::hasColumn('crew_timesheet_segments', 'vessel_id')) {
                $table->foreignId('vessel_id')
                    ->nullable()
                    ->constrained('vessels', indexName: 'cts_vessel_fk')
                    ->restrictOnDelete();
            }

            if (! Schema::hasColumn('crew_timesheet_segments', 'client_id')) {
                $table->foreignId('client_id')
                    ->nullable()
                    ->constrained('clients', indexName: 'cts_client_fk')
                    ->restrictOnDelete();
            }

            if (! Schema::hasColumn('crew_timesheet_segments', 'rank_id')) {
                $table->foreignId('rank_id')
                    ->nullable()
                    ->constrained('ranks', indexName: 'cts_rank_fk')
                    ->restrictOnDelete();
            }
        });
    }
};
