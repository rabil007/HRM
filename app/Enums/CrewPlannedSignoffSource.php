<?php

namespace App\Enums;

enum CrewPlannedSignoffSource: string
{
    case TourOfDuty = 'tour_of_duty';
    case ExistingPlan = 'existing_plan';
    case ManualOverride = 'manual_override';

    public function label(): string
    {
        return match ($this) {
            self::TourOfDuty => 'Tour of Duty',
            self::ExistingPlan => 'Existing Plan',
            self::ManualOverride => 'Manual Override',
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
