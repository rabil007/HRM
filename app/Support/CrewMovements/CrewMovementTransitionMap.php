<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewPhaseCode;

final class CrewMovementTransitionMap
{
    /**
     * Same-assignment next phases. Plan sign-off stays in P4 (not a phase transition).
     * P5/P6 → P0 is a linked-assignment start, not a same-assignment transition.
     *
     * @return array<string, list<CrewPhaseCode>>
     */
    private static function withinAssignmentMap(): array
    {
        return [
            CrewPhaseCode::PreMobilisation->value => [
                CrewPhaseCode::TravelIn,
            ],
            CrewPhaseCode::TravelIn->value => [
                CrewPhaseCode::JoinStandby,
                CrewPhaseCode::ReadyToJoin,
            ],
            CrewPhaseCode::JoinStandby->value => [
                CrewPhaseCode::Training,
                CrewPhaseCode::ReadyToJoin,
                CrewPhaseCode::OnVessel,
            ],
            CrewPhaseCode::Training->value => [
                CrewPhaseCode::JoinStandby,
                CrewPhaseCode::ReadyToJoin,
            ],
            CrewPhaseCode::ReadyToJoin->value => [
                CrewPhaseCode::OnVessel,
            ],
            CrewPhaseCode::OnVessel->value => [
                CrewPhaseCode::DemobStandby,
                CrewPhaseCode::HomeRedeploy,
            ],
            CrewPhaseCode::DemobStandby->value => [
                CrewPhaseCode::HomeRedeploy,
            ],
            CrewPhaseCode::HomeRedeploy->value => [],
        ];
    }

    /**
     * @return list<CrewPhaseCode>
     */
    public static function allowedNextPhases(CrewPhaseCode $phase): array
    {
        return self::withinAssignmentMap()[$phase->value] ?? [];
    }

    public static function canTransitionWithinAssignment(CrewPhaseCode $from, CrewPhaseCode $to): bool
    {
        foreach (self::allowedNextPhases($from) as $allowed) {
            if ($allowed === $to) {
                return true;
            }
        }

        return false;
    }

    public static function canStartLinkedAssignment(CrewPhaseCode $from): bool
    {
        return $from === CrewPhaseCode::DemobStandby
            || $from === CrewPhaseCode::HomeRedeploy;
    }

    /**
     * @deprecated Use canTransitionWithinAssignment() for same-assignment moves.
     */
    public static function canTransition(CrewPhaseCode $from, CrewPhaseCode $to): bool
    {
        return self::canTransitionWithinAssignment($from, $to);
    }
}
