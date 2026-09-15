<?php

namespace App\Enums;

enum CrewPhaseCode: string
{
    case PreMobilisation = 'p0';
    case TravelIn = 'p1';
    case JoinStandby = 'p2a';
    case Training = 'p2b';
    case ReadyToJoin = 'p3';
    case OnVessel = 'p4';
    case DemobStandby = 'p5';
    case HomeRedeploy = 'p6';

    public function label(): string
    {
        return match ($this) {
            self::PreMobilisation => 'Pre-Mobilisation',
            self::TravelIn => 'Travel In',
            self::JoinStandby => 'Join Standby',
            self::Training => 'Training',
            self::ReadyToJoin => 'Ready to Join',
            self::OnVessel => 'On Vessel',
            self::DemobStandby => 'Demobilisation Standby',
            self::HomeRedeploy => 'Home / Redeployment',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isPreJoin(): bool
    {
        return in_array($this, [
            self::PreMobilisation,
            self::TravelIn,
            self::JoinStandby,
            self::Training,
            self::ReadyToJoin,
        ], true);
    }

    /**
     * Phases that Operations may record as the first known stage when starting an assignment.
     *
     * P2B, P4, P5, and P6 cannot be used as a direct start. Join Vessel remains the only
     * way to enter On Vessel.
     *
     * @return list<self>
     */
    public static function directStartPhases(): array
    {
        return [
            self::PreMobilisation,
            self::TravelIn,
            self::JoinStandby,
            self::ReadyToJoin,
        ];
    }

    public function allowsDirectStart(): bool
    {
        return in_array($this, self::directStartPhases(), true);
    }
}
