<?php

namespace App\Enums;

enum CrewReliefRisk: string
{
    case None = 'none';
    case Warning = 'warning';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::None => 'None',
            self::Warning => 'At Risk',
            self::Critical => 'Critical Risk',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return list<self>
     */
    public static function filterable(): array
    {
        return [
            self::Warning,
            self::Critical,
        ];
    }
}
