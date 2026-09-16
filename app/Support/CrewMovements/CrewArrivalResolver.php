<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewPhaseCode;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use Carbon\CarbonInterface;

final class CrewArrivalResolver
{
    public static function timestamp(CrewAssignment $assignment): ?CarbonInterface
    {
        if (! $assignment->relationLoaded('phases')) {
            $assignment->loadMissing('phases');
        }

        $sortedPhases = $assignment->phases->sortBy('sequence');

        $p2aPhase = $sortedPhases->first(
            fn (CrewAssignmentPhase $phase): bool => $phase->phase_code === CrewPhaseCode::JoinStandby && $phase->actual_start_at !== null
        );

        if ($p2aPhase !== null) {
            return $p2aPhase->actual_start_at;
        }

        $p1Phase = $sortedPhases->first(
            fn (CrewAssignmentPhase $phase): bool => $phase->phase_code === CrewPhaseCode::TravelIn && $phase->actual_end_at !== null
        );

        if ($p1Phase !== null) {
            return $p1Phase->actual_end_at;
        }

        return null;
    }

    public static function date(CrewAssignment $assignment, string $timezone): ?string
    {
        $timestamp = self::timestamp($assignment);

        return $timestamp?->copy()->timezone($timezone)->toDateString();
    }
}
