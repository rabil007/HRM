<?php

use App\Support\Migrations\CrewSixPhaseLifecyclePhaseCodeRemap;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remap stored crew phase codes to the six-phase lifecycle.
     *
     * Obsolete Travel In (p1) and Ready to Join (legacy p3) are staged then migration
     * aborts until operators clear them (for example via guarded crew:reset on disposable data).
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
