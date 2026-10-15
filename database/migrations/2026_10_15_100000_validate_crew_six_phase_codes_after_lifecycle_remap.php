<?php

use App\Support\Migrations\CrewSixPhaseLifecyclePhaseCodeRemap;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ensures no staging markers or legacy phase strings remain after the lifecycle remap.
     *
     * Runs on every environment after the remap migration. Does not reinterpret data.
     */
    public function up(): void
    {
        if (! Schema::hasTable('crew_assignment_phases')) {
            return;
        }

        (new CrewSixPhaseLifecyclePhaseCodeRemap)->assertAllConfiguredTablesOnlyAllowedCodes();
    }

    public function down(): void
    {
        // Validation only.
    }
};
