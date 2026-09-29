<?php

namespace App\Support\Reports\CrewRelief;

use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;

final class CrewReliefPlanKey
{
    public static function for(CrewAssignment|CrewPlanningAssignment|null $plan): ?string
    {
        if ($plan === null) {
            return null;
        }

        if ($plan instanceof CrewAssignment) {
            return self::forAssignment((int) $plan->id);
        }

        return self::forPlanning((int) $plan->id);
    }

    public static function forAssignment(int $id): string
    {
        return "assignment:{$id}";
    }

    public static function forPlanning(int $id): string
    {
        return "planning:{$id}";
    }
}
