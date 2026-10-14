<?php

namespace App\Enums;

enum CrewPhaseCode: string
{
    case PreMobilisation = 'p0';
    case JoinStandby = 'p1a';
    case Training = 'p1b';
    case OnVessel = 'p2';
    case DemobStandby = 'p3';
    case HomeRedeploy = 'p4';

    public function label(): string
    {
        return match ($this) {
            self::PreMobilisation => 'Pre-Arrival',
            self::JoinStandby => 'Pre-Joining Standby',
            self::Training => 'Training',
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
            self::JoinStandby,
            self::Training,
        ], true);
    }

    /**
     * @return list<string>
     */
    public static function preJoinValues(): array
    {
        return [
            self::PreMobilisation->value,
            self::JoinStandby->value,
            self::Training->value,
        ];
    }

    /**
     * Phases Operations may choose as the first known stage on manual Start Assignment.
     *
     * Normal web Start Assignment begins at P0. P1A supports Already Arrived without fabricating P0 history.
     * Redeploy and Join Vessel remain the paths into later phases.
     *
     * @return list<self>
     */
    public static function directStartPhases(): array
    {
        return [
            self::PreMobilisation,
        ];
    }

    public function allowsDirectStart(): bool
    {
        return in_array($this, self::directStartPhases(), true);
    }

    /**
     * Product-facing phases for filters and report timelines.
     *
     * @return list<self>
     */
    public static function normalVisiblePhases(): array
    {
        return self::cases();
    }

    /**
     * Pre-vessel phases eligible for planned-join manning forecasts.
     *
     * @return list<self>
     */
    public static function plannedJoinForecastPhases(): array
    {
        return [
            self::PreMobilisation,
            self::JoinStandby,
            self::Training,
        ];
    }
}
