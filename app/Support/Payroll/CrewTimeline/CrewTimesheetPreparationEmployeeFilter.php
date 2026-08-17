<?php

namespace App\Support\Payroll\CrewTimeline;

use App\Models\Employee;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeDirectoryQuery;

final class CrewTimesheetPreparationEmployeeFilter
{
    /**
     * @param  list<array<string, mixed>>  $employees
     * @return list<array<string, mixed>>
     */
    public function apply(
        int $companyId,
        array $employees,
        CrewTimesheetPreparationReviewFilters $filters,
    ): array {
        if (! $filters->isActive() || $employees === []) {
            return $employees;
        }

        if ($filters->departmentId === '' && $filters->positionId === '') {
            $search = mb_strtolower($filters->search);

            return array_values(array_filter(
                $employees,
                fn (array $employee): bool => $this->matchesSearch($employee, $search),
            ));
        }

        $employeeIds = array_values(array_unique(array_map(
            fn (array $employee): int => (int) $employee['employee_id'],
            $employees,
        )));

        $query = Employee::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $employeeIds === [] ? [0] : $employeeIds);

        EmployeeDirectoryQuery::applyAttributeFilters(
            $query,
            $companyId,
            new EmployeeDirectoryFilters(
                departmentId: $filters->departmentId,
                positionId: $filters->positionId,
            ),
            exceptStatus: true,
        );

        $matchingIds = array_fill_keys(
            $query->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            true,
        );

        $search = mb_strtolower($filters->search);

        return array_values(array_filter(
            $employees,
            function (array $employee) use ($matchingIds, $search): bool {
                if (! isset($matchingIds[(int) $employee['employee_id']])) {
                    return false;
                }

                if ($search === '') {
                    return true;
                }

                return $this->matchesSearch($employee, $search);
            },
        ));
    }

    /**
     * @param  array<string, mixed>  $employee
     */
    private function matchesSearch(array $employee, string $search): bool
    {
        $haystack = [
            (string) ($employee['employee_name'] ?? ''),
            (string) ($employee['employee_number'] ?? ''),
            (string) ($employee['assignment_number'] ?? ''),
            (string) ($employee['vessel'] ?? ''),
            (string) ($employee['rank'] ?? ''),
        ];

        foreach ($employee['assignments'] ?? [] as $assignment) {
            if (! is_array($assignment)) {
                continue;
            }

            $haystack[] = (string) ($assignment['assignment_number'] ?? '');
            $haystack[] = (string) ($assignment['vessel'] ?? '');
            $haystack[] = (string) ($assignment['rank'] ?? '');
        }

        return str_contains(mb_strtolower(implode(' ', $haystack)), $search);
    }
}
