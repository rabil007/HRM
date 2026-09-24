<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
            fn (CrewAssignmentPhase $phase): bool => $phase->phase_code === CrewPhaseCode::TravelIn
                && $phase->status === CrewPhaseStatus::Completed
                && $phase->actual_end_at !== null
        );

        if ($p1Phase !== null) {
            return $p1Phase->actual_end_at;
        }

        return null;
    }

    /**
     * SQL filter equivalent of timestamp(): P2A actual_start_at first,
     * else completed P1 actual_end_at only when no P2A arrival exists.
     *
     * @param  Builder<Model>  $query
     * @param  '>='|'<='  $operator
     */
    public static function applyDateFilter(Builder $query, string $operator, string $date): Builder
    {
        return $query->where(function (Builder $outer) use ($operator, $date): void {
            $outer
                ->whereHas(
                    'phases',
                    fn (Builder $phase) => $phase
                        ->where('phase_code', CrewPhaseCode::JoinStandby)
                        ->whereNotNull('actual_start_at')
                        ->whereDate('actual_start_at', $operator, $date),
                )
                ->orWhere(function (Builder $legacy) use ($operator, $date): void {
                    $legacy
                        ->whereDoesntHave(
                            'phases',
                            fn (Builder $phase) => $phase
                                ->where('phase_code', CrewPhaseCode::JoinStandby)
                                ->whereNotNull('actual_start_at'),
                        )
                        ->whereHas(
                            'phases',
                            fn (Builder $phase) => $phase
                                ->where('phase_code', CrewPhaseCode::TravelIn)
                                ->where('status', CrewPhaseStatus::Completed)
                                ->whereNotNull('actual_end_at')
                                ->whereDate('actual_end_at', $operator, $date),
                        );
                });
        });
    }

    public static function date(CrewAssignment $assignment, string $timezone): ?string
    {
        $timestamp = self::timestamp($assignment);

        return $timestamp?->copy()->timezone($timezone)->toDateString();
    }
}
