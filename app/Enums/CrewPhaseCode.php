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
}
