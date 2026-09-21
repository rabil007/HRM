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
                                    ->from('crew_planning_assignments as cpa')
                                    ->join('employees as planning_employees', 'planning_employees.id', '=', 'cpa.employee_id')
                                    ->whereColumn('cpa.id', 'activity_log.subject_id')
                                    ->where('cpa.company_id', $companyId)
                                    ->where('planning_employees.company_id', $companyId)
                                    ->whereIn('planning_employees.department_id', $allowedDepartmentIds)
                                    ->where(function ($relieved) use ($companyId, $allowedDepartmentIds) {
                                        $relieved->whereNull('cpa.relieves_crew_assignment_id')
                                            ->orWhereExists(function ($relievedQuery) use ($companyId, $allowedDepartmentIds) {
                                                $relievedQuery->selectRaw(1)
                                                    ->from('crew_assignments as relieved_assignments')
                                                    ->join('employees as relieved_employees', 'relieved_employees.id', '=', 'relieved_assignments.employee_id')
                                                    ->whereColumn('relieved_assignments.id', 'cpa.relieves_crew_assignment_id')
                                                    ->where('relieved_assignments.company_id', $companyId)
                                                    ->where('relieved_employees.company_id', $companyId)
                                                    ->whereIn('relieved_employees.department_id', $allowedDepartmentIds);
                                            });
                                    });
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

        return ActivityChangePresenter::presentLogs($logs, $companyId, $user)
            ->map(function (Activity $log): array {
                $row = ActivityChangePresenter::toRecentActivityArray($log);
                $row['description'] = $log->description ?? '';

                return $row;
            })
            ->values()
            ->all();
    }
}
