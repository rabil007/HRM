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
     * SQL filter equivalent of timestamp(): the first P2A actual_start_at by sequence,
     * else the first completed P1 actual_end_at by sequence when no P2A arrival exists.
     *
     * Applies from/to against that single resolved occurrence (not any matching phase).
     *
     * @param  Builder<Model>  $query
     */
    public static function applyDateFilter(
        Builder $query,
        ?string $from = null,
        ?string $to = null,
    ): Builder {
        $from = ($from !== null && $from !== '') ? $from : null;
        $to = ($to !== null && $to !== '') ? $to : null;

        if ($from === null && $to === null) {
            return $query;
        }

        $expression = self::authoritativeArrivalTimestampSql($query);

        return $query->where(function (Builder $inner) use ($expression, $from, $to): void {
            if ($from !== null) {
                $inner->whereRaw("date({$expression}) >= ?", [$from]);
            }

            if ($to !== null) {
                $inner->whereRaw("date({$expression}) <= ?", [$to]);
            }
        });
    }

    /**
     * @param  Builder<Model>  $query
     */
    private static function authoritativeArrivalTimestampSql(Builder $query): string
    {
        $grammar = $query->getQuery()->getGrammar();
        $assignmentTable = $grammar->wrapTable($query->getModel()->getTable());
        $phasesTable = $grammar->wrapTable((new CrewAssignmentPhase)->getTable());
        $assignmentId = $grammar->wrap('id');
        $phaseAssignmentId = $grammar->wrap('crew_assignment_id');
        $phaseCode = $grammar->wrap('phase_code');
        $status = $grammar->wrap('status');
        $sequence = $grammar->wrap('sequence');
        $actualStart = $grammar->wrap('actual_start_at');
        $actualEnd = $grammar->wrap('actual_end_at');
        $deletedAt = $grammar->wrap('deleted_at');

        $p2a = CrewPhaseCode::JoinStandby->value;
        $p1 = CrewPhaseCode::TravelIn->value;
        $completed = CrewPhaseStatus::Completed->value;

        $p2aSubquery = <<<SQL
(
    select {$actualStart}
    from {$phasesTable}
    where {$phaseAssignmentId} = {$assignmentTable}.{$assignmentId}
      and {$phaseCode} = '{$p2a}'
      and {$actualStart} is not null
      and {$deletedAt} is null
    order by {$sequence} asc
    limit 1
)
SQL;

        $p1Subquery = <<<SQL
(
    select {$actualEnd}
    from {$phasesTable}
    where {$phaseAssignmentId} = {$assignmentTable}.{$assignmentId}
      and {$phaseCode} = '{$p1}'
      and {$status} = '{$completed}'
      and {$actualEnd} is not null
      and {$deletedAt} is null
    order by {$sequence} asc
    limit 1
)
SQL;

        return "coalesce({$p2aSubquery}, {$p1Subquery})";
    }

    public static function date(CrewAssignment $assignment, string $timezone): ?string
    {
        $timestamp = self::timestamp($assignment);

        return $timestamp?->copy()->timezone($timezone)->toDateString();
    }
}
