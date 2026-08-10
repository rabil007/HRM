<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Models\CrewAssignment;

class CrewAssignmentEditability
{
    /**
     * Generic assignment editing is allowed only during Draft or pre-P4 mobilisation.
     * Once P4 On Vessel or later phase begins, generic edit is disabled.
     */
    public static function isEditable(CrewAssignment $assignment): bool
    {
        if ($assignment->trashed()) {
            return false;
        }

        if ($assignment->status === CrewAssignmentStatus::Draft) {
            return true;
        }

        if ($assignment->status !== CrewAssignmentStatus::Active) {
            return false;
        }

        $current = $assignment->currentPhase;

        if ($current === null) {
            return false;
        }

        return in_array($current->phase_code, [
            CrewPhaseCode::PreMobilisation,
            CrewPhaseCode::TravelIn,
            CrewPhaseCode::JoinStandby,
            CrewPhaseCode::Training,
            CrewPhaseCode::ReadyToJoin,
        ], true);
    }
}
