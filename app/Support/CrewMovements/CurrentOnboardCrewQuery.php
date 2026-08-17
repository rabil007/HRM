<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Support\Employees\ActiveEmployeeConstraint;
use Illuminate\Database\Eloquent\Builder;

/**
 * Authoritative current-onboard (active P4) constraint shared by Current Crew
 * Vessel View, vessel manning actuals, and onboard Excel export.
 */
final class CurrentOnboardCrewQuery
{
    /**
     * @param  Builder<CrewAssignment>  $query
     * @return Builder<CrewAssignment>
     */
    public static function applyConstraint(Builder $query, int $companyId): Builder
    {
        $query
            ->where($query->qualifyColumn('company_id'), $companyId)
            ->where('status', CrewAssignmentStatus::Active)
            ->whereNotNull('vessel_id')
            ->whereHas('currentPhase', function (Builder $phase): void {
                $phase->where('phase_code', CrewPhaseCode::OnVessel)
                    ->where('status', CrewPhaseStatus::Active);
            });

        ActiveEmployeeConstraint::whereHas($query, $companyId);

        return $query;
    }

    /**
     * Filtered current-onboard assignments for the company.
     *
     * Vessel View is P4-only. Non-P4 phase filters and non-active status
     * filters therefore match nothing rather than mixing in planned crew.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<CrewAssignment>
     */
    public static function assignments(int $companyId, array $filters = []): Builder
    {
        $query = CrewAssignment::query();
        self::applyConstraint($query, $companyId);

        if (self::filtersExcludeOnboard($filters)) {
            $query->whereRaw('0 = 1');

            return $query;
        }

        CurrentCrewQuery::applySharedFilters($query, $companyId, $filters);

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private static function filtersExcludeOnboard(array $filters): bool
    {
        $phase = trim((string) ($filters['phase'] ?? ''));

        if ($phase !== '' && $phase !== CrewPhaseCode::OnVessel->value) {
            return true;
        }

        $status = trim((string) ($filters['status'] ?? ''));

        if ($status !== '' && $status !== CrewAssignmentStatus::Active->value) {
            return true;
        }

        return false;
    }
}
