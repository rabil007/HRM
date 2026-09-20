<?php

namespace App\Support\CrewOperations;

use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Models\User;
use App\Support\Activity\ActivityChangePresenter;
use App\Support\Employees\EmployeeVisibilityScope;
use Spatie\Activitylog\Models\Activity;

final class CrewOperationsRecentActivityQuery
{
    /**
     * @return list<array{
     *     id: int,
     *     event: string|null,
     *     description: string|null,
     *     causer: array{id: int, name: string, email: string}|null,
     *     old_values: mixed,
     *     new_values: mixed,
     *     created_at: mixed
     * }>
     */
    public static function forCompany(?User $user, int $companyId, int $limit = 10): array
    {
        if (! $user?->can('audit.view')) {
            return [];
        }

        $query = Activity::query()
            ->where('company_id', $companyId)
            ->whereIn('subject_type', [
                CrewAssignment::class,
                CrewPlanningAssignment::class,
            ]);

        if (! EmployeeVisibilityScope::hasUnrestrictedAccess($user, $companyId)) {
            $allowedDepartmentIds = EmployeeVisibilityScope::allowedDepartmentIds($user, $companyId);
            if ($allowedDepartmentIds === null) {
                // unrestricted
            } elseif ($allowedDepartmentIds === []) {
                return [];
            } else {
                $query->where(function ($sub) use ($companyId, $allowedDepartmentIds) {
                    $sub->where(function ($q1) use ($companyId, $allowedDepartmentIds) {
                        $q1->where('subject_type', CrewAssignment::class)
                            ->whereExists(function ($subQuery) use ($companyId, $allowedDepartmentIds) {
                                $subQuery->selectRaw(1)
                                    ->from('crew_assignments')
                                    ->join('employees', 'employees.id', '=', 'crew_assignments.employee_id')
                                    ->whereColumn('crew_assignments.id', 'activity_log.subject_id')
                                    ->where('employees.company_id', $companyId)
                                    ->whereIn('employees.department_id', $allowedDepartmentIds);
                            });
                    })->orWhere(function ($q2) use ($companyId, $allowedDepartmentIds) {
                        $q2->where('subject_type', CrewPlanningAssignment::class)
                            ->whereExists(function ($subQuery) use ($companyId, $allowedDepartmentIds) {
                                $subQuery->selectRaw(1)
                                    ->from('crew_planning_assignments')
                                    ->join('employees', 'employees.id', '=', 'crew_planning_assignments.employee_id')
                                    ->whereColumn('crew_planning_assignments.id', 'activity_log.subject_id')
                                    ->where('employees.company_id', $companyId)
                                    ->whereIn('employees.department_id', $allowedDepartmentIds);
                            });
                    });
                });
            }
        }

        $logs = $query
            ->with(['causer:id,name,email'])
            ->latest('id')
            ->limit($limit)
            ->get();

        return ActivityChangePresenter::presentLogs($logs, $companyId)
            ->map(function (Activity $log): array {
                $row = ActivityChangePresenter::toRecentActivityArray($log);
                $row['description'] = $log->description ?? '';

                return $row;
            })
            ->values()
            ->all();
    }
}
