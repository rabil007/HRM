<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewPhaseCode;

final class CrewMovementTransitionMap
{
    /**
     * Structural next phases for a movement cycle.
     * Plan sign-off stays in P4 and does not appear as a phase transition.
     * P5/P6 → P0 means P0 of a linked new assignment (transfer / redeploy).
     *
     * @return array<string, list<CrewPhaseCode>>
     */
    private static function map(): array
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
                CrewPhaseCode::PreMobilisation,
            ],
            CrewPhaseCode::HomeRedeploy->value => [
                CrewPhaseCode::PreMobilisation,
            ],
        ];
    }

    /**
     * @return list<CrewPhaseCode>
     */
    public static function allowedNextPhases(CrewPhaseCode $phase): array
    {
        return self::map()[$phase->value] ?? [];
    }

    public static function canTransition(CrewPhaseCode $from, CrewPhaseCode $to): bool
    {
        foreach (self::allowedNextPhases($from) as $allowed) {
            if ($allowed === $to) {
                return true;
            }
        }

        return false;
    }
}
