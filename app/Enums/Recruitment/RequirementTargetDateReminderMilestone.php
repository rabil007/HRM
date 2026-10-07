<?php

namespace App\Enums\Recruitment;

enum RequirementTargetDateReminderMilestone: string
{
    case ThreeDaysBefore = 'three_days_before';
    case TargetDay = 'target_day';

    public function daysBeforeTarget(): int
    {
        return match ($this) {
            self::ThreeDaysBefore => 3,
            self::TargetDay => 0,
        };
    }

    public function subjectPrefix(string $requirementNumber): string
    {
        return match ($this) {
            self::ThreeDaysBefore => "Requirement {$requirementNumber} is due in 3 days",
            self::TargetDay => "Requirement {$requirementNumber} is due today",
        };
    }

    public function heading(): string
    {
        return match ($this) {
            self::ThreeDaysBefore => 'Target Date reminder — due in 3 days',
            self::TargetDay => 'Target Date reminder — due today',
        };
    }
}
