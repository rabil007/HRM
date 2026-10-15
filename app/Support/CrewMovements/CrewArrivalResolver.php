<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewPhaseCode;
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

        $companyId = $assignment->company_id !== null ? (int) $assignment->company_id : null;
        $sortedPhases = $assignment->phases
            ->filter(function (CrewAssignmentPhase $phase) use ($companyId): bool {
                if ($companyId === null) {
                    return true;
                }

                if (array_key_exists('company_id', $phase->getAttributes())) {
                    return (int) $phase->company_id === $companyId;
                }

                if (isset($phase->company_id)) {
                    return (int) $phase->company_id === $companyId;
                }

                return true;
            })
            ->sortBy('sequence');

        $joinStandbyPhase = $sortedPhases->first(
            fn (CrewAssignmentPhase $phase): bool => $phase->phase_code === CrewPhaseCode::JoinStandby && $phase->actual_start_at !== null
        );

        return $joinStandbyPhase?->actual_start_at;
    }

    /**
     * SQL filter equivalent of timestamp(): the first P1A actual_start_at by sequence.
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
    public static function arrivalTimestampSql(Builder $query): string
    {
        return self::authoritativeArrivalTimestampSql($query);
    }

    /**
     * @param  Builder<Model>  $query
     */
    public static function applyOrderBy(Builder $query, string $direction = 'asc'): Builder
    {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $expression = self::authoritativeArrivalTimestampSql($query);

        return $query->orderByRaw("{$expression} {$direction}");
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
        $phaseCompanyId = $grammar->wrap('company_id');
        $assignmentCompanyId = $grammar->wrap('company_id');
        $phaseCode = $grammar->wrap('phase_code');
        $sequence = $grammar->wrap('sequence');
        $actualStart = $grammar->wrap('actual_start_at');
        $deletedAt = $grammar->wrap('deleted_at');

        $p1a = CrewPhaseCode::JoinStandby->value;

        return <<<SQL
(
    select {$actualStart}
    from {$phasesTable}
    where {$phasesTable}.{$phaseAssignmentId} = {$assignmentTable}.{$assignmentId}
      and {$phasesTable}.{$phaseCompanyId} = {$assignmentTable}.{$assignmentCompanyId}
      and {$phaseCode} = '{$p1a}'
      and {$actualStart} is not null
      and {$deletedAt} is null
    order by {$sequence} asc
    limit 1
)
SQL;
    }

    public static function date(CrewAssignment $assignment, string $timezone): ?string
    {
        $timestamp = self::timestamp($assignment);

        return $timestamp?->copy()->timezone($timezone)->toDateString();
    }
}
