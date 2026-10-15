<?php

use App\Support\Migrations\CrewSixPhaseLifecyclePhaseCodeRemap;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Repair legacy phase codes left inside scheduled movement action_payload JSON.
     *
     * Column remap already rewrote expected_*_phase_code. Payload next_phase and
     * starting_phase were not updated, so pending disembarkation, training
     * completion, and redeploy schedules execute against retired codes.
     *
     * Only unresolved (scheduled / processing / needs_attention) rows are updated.
     * Executed and cancelled audit payloads are preserved.
     */
    public function up(): void
    {
        (new CrewSixPhaseLifecyclePhaseCodeRemap)->remapScheduledMovementPayloadPhaseCodes();
    }

    public function down(): void
    {
        // Irreversible: legacy payload codes overlap the six-phase codes.
    }
};
