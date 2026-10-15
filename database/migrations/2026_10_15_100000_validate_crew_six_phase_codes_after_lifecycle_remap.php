<?php

use App\Support\Migrations\CrewSixPhaseLifecyclePhaseCodeRemap;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ensures only finalized six-phase codes remain and records completion when safe.
     *
     * Safe for databases that already applied an earlier remap revision: modern or
     * empty datasets are marked complete without reinterpreting overlapping p3/p4.
     */
    public function up(): void
    {
        if (! Schema::hasTable('crew_assignment_phases')) {
            return;
        }

        $remapper = new CrewSixPhaseLifecyclePhaseCodeRemap;
        $remapper->ensureStateTable();
        $remapper->assertAllConfiguredTablesOnlyAllowedCodes();

        if ($remapper->isComplete()) {
            return;
        }

        $shape = $remapper->classifyAllConfiguredTables();

        if (in_array($shape, ['empty', 'modern'], true)) {
            $remapper->markComplete();

            return;
        }

        // Ambiguous / mixed / staging: do not guess. Remap migration should have
        // failed earlier; if only this migration is pending, fail with guidance.
        if ($shape !== 'legacy') {
            throw new RuntimeException(
                "Crew six-phase validation cannot mark remap complete: dataset shape is [{$shape}]. "
                .'Overlapping phase codes (p3/p4) prevent safe inference. '
                .'For disposable local/development data use `php artisan crew:reset --dry-run` then `crew:reset` '
                .'after verifying database identity. Do not delete employees, master data, or protected payroll.'
            );
        }

        // Legacy rows still present and remap migration somehow skipped — run remap once.
        $remapper->remapAllConfiguredTables();
    }

    public function down(): void
    {
        // Validation / marker only.
    }
};
