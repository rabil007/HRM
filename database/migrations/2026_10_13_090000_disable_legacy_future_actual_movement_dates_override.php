<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retire the Phase 1 future-actual testing override at rest without dropping
 * the column (older settings rows and audit history remain intact).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crew_operations_settings')) {
            return;
        }

        if (! Schema::hasColumn('crew_operations_settings', 'allow_future_actual_movement_dates')) {
            return;
        }

        DB::table('crew_operations_settings')
            ->where('allow_future_actual_movement_dates', true)
            ->update(['allow_future_actual_movement_dates' => false]);
    }

    public function down(): void
    {
        // Intentionally empty — do not re-enable testing overrides.
    }
};
