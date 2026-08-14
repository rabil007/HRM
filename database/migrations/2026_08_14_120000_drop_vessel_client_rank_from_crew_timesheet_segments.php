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

        $driver = Schema::getConnection()->getDriverName();

        Schema::table('crew_timesheet_segments', function (Blueprint $table) use ($driver): void {
            if (Schema::hasColumn('crew_timesheet_segments', 'vessel_id')) {
                $this->dropForeignForColumn($table, $driver, 'cts_vessel_fk', 'vessel_id');
                $table->dropColumn('vessel_id');
            }

            if (Schema::hasColumn('crew_timesheet_segments', 'client_id')) {
                $this->dropForeignForColumn($table, $driver, 'cts_client_fk', 'client_id');
                $table->dropColumn('client_id');
            }

            if (Schema::hasColumn('crew_timesheet_segments', 'rank_id')) {
                $this->dropForeignForColumn($table, $driver, 'cts_rank_fk', 'rank_id');
                $table->dropColumn('rank_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('crew_timesheet_segments')) {
            return;
        }

        Schema::table('crew_timesheet_segments', function (Blueprint $table): void {
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

    private function dropForeignForColumn(Blueprint $table, string $driver, string $customName, string $columnName): void
    {
        if ($driver === 'sqlite') {
            $table->dropForeign([$columnName]);

            return;
        }

        try {
            $table->dropForeign($customName);
        } catch (Throwable) {
            try {
                $table->dropForeign([$columnName]);
            } catch (Throwable) {
                // Foreign key constraint already dropped or does not exist
            }
        }
    }
};
