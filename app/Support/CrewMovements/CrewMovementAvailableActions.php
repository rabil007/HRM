<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;

class CrewMovementAvailableActions
{
    /**
     * @return list<string>
     */
    public static function for(CrewAssignment $assignment): array
    {
        $status = $assignment->status;
        $current = $assignment->currentPhase;

        if ($current === null) {
            return $status === CrewAssignmentStatus::Draft
                ? [CrewMovementAction::CancelAssignment->value]
                : [];
        }

        $phase = $current->phase_code;
        $phaseStatus = $current->status;

        if ($status === CrewAssignmentStatus::Draft && $phase === CrewPhaseCode::PreMobilisation
            && $phaseStatus === CrewPhaseStatus::Planned) {
            return [
                CrewMovementAction::ApproveMobilisation->value,
                CrewMovementAction::CancelAssignment->value,
            ];
        }

        if ($status === CrewAssignmentStatus::Active) {
            return match ($phase) {
                CrewPhaseCode::TravelIn => [
                    CrewMovementAction::RecordArrival->value,
                    CrewMovementAction::CancelAssignment->value,
                ],
                CrewPhaseCode::JoinStandby => [
                    CrewMovementAction::SendToTraining->value,
                    CrewMovementAction::MarkReady->value,
                    CrewMovementAction::JoinVessel->value,
                    CrewMovementAction::CancelAssignment->value,
                ],
                CrewPhaseCode::Training => [
                    CrewMovementAction::CompleteTraining->value,
                    CrewMovementAction::CancelAssignment->value,
                ],
                CrewPhaseCode::ReadyToJoin => [
                    CrewMovementAction::JoinVessel->value,
                    CrewMovementAction::CancelAssignment->value,
                ],
                CrewPhaseCode::OnVessel => [
                    CrewMovementAction::PlanSignoff->value,
                    CrewMovementAction::ConfirmDisembarkation->value,
                ],
                CrewPhaseCode::DemobStandby => [
                    CrewMovementAction::TravelHome->value,
                    CrewMovementAction::CancelAssignment->value,
                ],
                CrewPhaseCode::HomeRedeploy => [
                    CrewMovementAction::CloseAssignment->value,
                    CrewMovementAction::CancelAssignment->value,
                ],
                default => [],
            };
        }

        return [];
    }
}
