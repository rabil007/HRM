<?php

namespace App\Support\CrewMovements;

use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Models\User;
use App\Support\Employees\EmployeeVisibilityScope;
use Illuminate\Database\Eloquent\Builder;

class CrewAssignmentAccess
{
    /**
     * Scope a CrewAssignment query to the given company and employee visibility for the user.
     *
     * @param  Builder<CrewAssignment>  $query
     * @return Builder<CrewAssignment>
     */
    public static function applyScope(Builder $query, int $companyId, ?User $user = null): Builder
    {
        $query->where('crew_assignments.company_id', $companyId);

        if ($user !== null) {
            EmployeeVisibilityScope::whereHas($query, $user, $companyId, 'employee');
        }

        return $query;
    }

    /**
     * Scope a CrewAssignment query for company and employee visibility.
     *
     * @return Builder<CrewAssignment>
     */
    public static function queryForCompany(int $companyId, ?User $user = null): Builder
    {
        return self::applyScope(CrewAssignment::query(), $companyId, $user);
    }

    public static function findForCompany(int $companyId, int $id, ?User $user = null): ?CrewAssignment
    {
        $assignment = CrewAssignment::query()
            ->where('company_id', $companyId)
            ->whereKey($id)
            ->with([
                'employee',
                'rank',
                'client',
                'vessel',
                'currentPhase',
                'phases',
                'planningAssignment',
            ])
            ->first();

        if ($assignment === null) {
            return null;
        }

        if ($user !== null) {
            $employee = $assignment->relationLoaded('employee') && $assignment->employee !== null
                ? $assignment->employee
                : Employee::withTrashed()
                    ->whereKey($assignment->employee_id)
                    ->where('company_id', $companyId)
                    ->first();

            if ($employee === null || ! EmployeeVisibilityScope::canAccess($user, $employee, $companyId)) {
                return null;
            }
        }

        return $assignment;
    }

    public static function assertInCompany(CrewAssignment $assignment, int $companyId, ?User $user = null): void
    {
        abort_unless((int) $assignment->company_id === $companyId, 404);

        if ($user !== null) {
            $employee = $assignment->relationLoaded('employee') && $assignment->employee !== null
                ? $assignment->employee
                : Employee::withTrashed()
                    ->whereKey($assignment->employee_id)
                    ->where('company_id', $companyId)
                    ->first();

            if ($employee === null) {
                abort(404);
            }

            abort_unless(EmployeeVisibilityScope::canAccess($user, $employee, $companyId), 404);
        }
    }
}
