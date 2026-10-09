<?php

namespace App\Support\CrewMovements\Scheduling;

use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;

/**
 * Physical / operational movements eligible for Schedule for Later.
 *
 * Plan Sign-off remains a forecast update. Cancel / Correct / Void stay
 * exceptional operator actions and are never auto-scheduled.
 */
final class CrewSchedulableMovementActions
{
    /**
     * @return list<CrewMovementAction>
     */
    public static function actions(): array
    {
        return [
            CrewMovementAction::ApproveMobilisation,
            CrewMovementAction::RecordArrival,
            CrewMovementAction::SendToTraining,
            CrewMovementAction::CompleteTraining,
            CrewMovementAction::JoinVessel,
            CrewMovementAction::ConfirmDisembarkation,
            CrewMovementAction::TravelHome,
            CrewMovementAction::TransferVessel,
            CrewMovementAction::Redeploy,
            CrewMovementAction::CloseAssignment,
        ];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (CrewMovementAction $action): string => $action->value,
            self::actions(),
        );
    }

    public static function isSchedulable(CrewMovementAction $action): bool
    {
        return in_array($action, self::actions(), true);
    }

    /**
     * Expected resulting phase after successful execution (same-assignment).
     * Transfer / Redeploy create linked assignments — null means "linked start".
     */
    public static function expectedResultPhase(
        CrewMovementAction $action,
        ?string $nextPhase = null,
    ): ?CrewPhaseCode {
        return match ($action) {
            CrewMovementAction::ApproveMobilisation => CrewPhaseCode::PreMobilisation,
            CrewMovementAction::RecordArrival => CrewPhaseCode::tryFrom((string) ($nextPhase ?: CrewPhaseCode::JoinStandby->value))
                ?? CrewPhaseCode::JoinStandby,
            CrewMovementAction::SendToTraining => CrewPhaseCode::Training,
            CrewMovementAction::CompleteTraining => CrewPhaseCode::tryFrom((string) ($nextPhase ?: CrewPhaseCode::JoinStandby->value))
                ?? CrewPhaseCode::JoinStandby,
            CrewMovementAction::JoinVessel => CrewPhaseCode::OnVessel,
            CrewMovementAction::ConfirmDisembarkation => CrewPhaseCode::tryFrom((string) ($nextPhase ?: CrewPhaseCode::DemobStandby->value))
                ?? CrewPhaseCode::DemobStandby,
            CrewMovementAction::TravelHome => CrewPhaseCode::HomeRedeploy,
            CrewMovementAction::CloseAssignment => null,
            CrewMovementAction::TransferVessel, CrewMovementAction::Redeploy => null,
            default => null,
        };
    }

    public static function expectedResultPhaseLabel(
        CrewMovementAction $action,
        ?string $nextPhase = null,
    ): string {
        if (in_array($action, [CrewMovementAction::TransferVessel, CrewMovementAction::Redeploy], true)) {
            return 'Linked assignment start';
        }

        if ($action === CrewMovementAction::CloseAssignment) {
            return 'Assignment closed';
        }

        $phase = self::expectedResultPhase($action, $nextPhase);

        return $phase?->label() ?? 'Operational transition';
    }
}
