<?php

use App\Support\Migrations\CrewSixPhaseLifecyclePhaseCodeRemap;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remap stored crew phase codes to the six-phase lifecycle (idempotent).
     *
     * Classifies legacy vs modern datasets before mutating overlapping codes (p3/p4).
     * Obsolete Travel In / Ready to Join abort safely. Successful remaps record a
     * durable completion marker so retries never double-convert.
     */
    public function up(): void
    {
        if (! Schema::hasTable('crew_assignment_phases')) {
            return;
        }

        (new CrewSixPhaseLifecyclePhaseCodeRemap)->remapAllConfiguredTables();
    }

    public function down(): void
    {
        // Irreversible: remapped lifecycle codes cannot be restored safely.
    }
};
