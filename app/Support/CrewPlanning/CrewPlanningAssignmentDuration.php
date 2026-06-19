<?php

namespace App\Support\CrewPlanning;

use Carbon\CarbonImmutable;

final class CrewPlanningAssignmentDuration
{
    public static function inclusiveDays(string $from, string $to): int
    {
        return (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1;
    }
}
