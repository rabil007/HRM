<?php

namespace App\Enums;

enum VesselManningHealthStatus: string
{
    case Healthy = 'healthy';
    case AtRisk = 'at_risk';
    case Critical = 'critical';
    case NotConfigured = 'not_configured';

    public function label(): string
    {
        return match ($this) {
            self::Healthy => 'Healthy',
            self::AtRisk => 'At Risk',
            self::Critical => 'Critical',
            self::NotConfigured => 'Manning Not Configured',
        };
    }

    public function sortRank(): int
    {
        return match ($this) {
            self::Critical => 0,
            self::AtRisk => 1,
            self::Healthy => 2,
            self::NotConfigured => 3,
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
