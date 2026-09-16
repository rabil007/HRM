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
     * Normal web Start Assignment always begins at P0. P1 is legacy compatibility only.
     * Redeploy and Join Vessel remain the paths into later phases. Join Vessel is still
     * the only way to enter On Vessel.
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

    public function isLegacy(): bool
    {
        return in_array($this, self::legacyPhases(), true);
    }

    public function legacyContextLabel(): ?string
    {
        if (! $this->isLegacy()) {
            return null;
        }

        return sprintf('Legacy phase · %s %s', strtoupper($this->value), $this->label());
    }

    /**
     * @return list<self>
     */
    public static function legacyPhases(): array
    {
        return [
            self::TravelIn,
            self::ReadyToJoin,
        ];
    }

    /**
     * Normal product-facing phases for filters and report timelines.
     *
     * @return list<self>
     */
    public static function normalVisiblePhases(): array
    {
        return [
            self::PreMobilisation,
            self::JoinStandby,
            self::Training,
            self::OnVessel,
            self::DemobStandby,
            self::HomeRedeploy,
        ];
    }
}
