<?php

namespace App\Support\Employees;

use App\Enums\CrewAssignmentStatus;
use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Support\CrewMovements\CrewAssignmentStatusResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class EmployeeCrewStatusFilter
{
    public const AVAILABLE = 'available';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'in_home' => 'In home',
            self::AVAILABLE => 'Available',
            'pre_mobilisation' => 'Pre-mobilisation',
            'travel_in' => 'Travel in',
            'join_standby' => 'Join standby',
            'training' => 'Training',
            'ready_to_join' => 'Ready to join',
            'on_vessel' => 'On vessel',
            'demob_standby' => 'Demob standby',
            'home_redeploy' => 'Home / redeploy',
            'movement_update_required' => 'Needs update',
        ];
    }

    public static function isValid(string $crewStatus): bool
    {
        return array_key_exists($crewStatus, self::options());
    }

    /**
     * @return list<int>
     */
    public static function matchingEmployeeIds(int $companyId, string $crewStatus): array
    {
        if (! self::isValid($crewStatus)) {
            return [];
        }

        if ($crewStatus === self::AVAILABLE) {
            return self::availableEmployeeIds($companyId);
        }

        if ($crewStatus === 'in_home') {
            return self::activeEmployeeIdsWithoutOpenAssignment($companyId);
        }

        $employeeIds = self::activeEmployeeIdsWithOpenAssignment($companyId);

        if ($employeeIds === []) {
            return [];
        }

        $statuses = (new CrewAssignmentStatusResolver)->forEmployeeIds($companyId, $employeeIds);

        return array_values(array_filter(
            $employeeIds,
            fn (int $employeeId): bool => ($statuses[$employeeId]['status'] ?? null) === $crewStatus,
        ));
    }

    /**
     * @return list<int>
     */
    private static function availableEmployeeIds(int $companyId): array
    {
        return self::activeEmployeesQuery($companyId)
            ->whereNotExists(self::assignmentExists($companyId, [CrewAssignmentStatus::Active, CrewAssignmentStatus::Draft]))
            ->whereNotExists(self::assignmentExists($companyId, [CrewAssignmentStatus::Completed]))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return list<int>
     */
    private static function activeEmployeeIdsWithoutOpenAssignment(int $companyId): array
    {
        return self::activeEmployeesQuery($companyId)
            ->whereNotExists(self::assignmentExists($companyId, [CrewAssignmentStatus::Active, CrewAssignmentStatus::Draft]))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return list<int>
     */
    private static function activeEmployeeIdsWithOpenAssignment(int $companyId): array
    {
        return CrewAssignment::query()
            ->where('company_id', $companyId)
            ->whereIn('status', [CrewAssignmentStatus::Active, CrewAssignmentStatus::Draft])
            ->whereIn('employee_id', self::activeEmployeesQuery($companyId)->select('id'))
            ->distinct()
            ->pluck('employee_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return Builder<Employee>
     */
    private static function activeEmployeesQuery(int $companyId): Builder
    {
        return Employee::query()
            ->where('company_id', $companyId)
            ->active();
    }

    /**
     * @param  list<CrewAssignmentStatus|string>  $statuses
     * @return \Closure(QueryBuilder): void
     */
    private static function assignmentExists(int $companyId, array $statuses): \Closure
    {
        return function (QueryBuilder $query) use ($companyId, $statuses): void {
            $query->selectRaw('1')
                ->from('crew_assignments')
                ->whereColumn('crew_assignments.employee_id', 'employees.id')
                ->where('crew_assignments.company_id', $companyId)
                ->whereIn('crew_assignments.status', $statuses);
        };
    }
}
