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
     * Phases Operations may choose as the first known stage on manual Start Assignment.
     *
     * Limited to P0 and P1 so payable Join Standby (P2A/P2B/P3) history is not skipped.
     * Redeploy and Join Vessel remain the paths into later phases. Join Vessel is still
     * the only way to enter On Vessel.
     *
     * @return list<self>
     */
    public static function directStartPhases(): array
    {
        return [
            self::PreMobilisation,
            self::TravelIn,
        ];
    }

    public function allowsDirectStart(): bool
    {
        return in_array($this, self::directStartPhases(), true);
    }
}
