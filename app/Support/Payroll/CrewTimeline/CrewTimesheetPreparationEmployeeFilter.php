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

        $filtered = $employees;

        if ($filters->departmentId !== '' || $filters->positionId !== '') {
            $filtered = $this->filterByDirectory($companyId, $filtered, $filters);
        }

        if ($filters->search !== '') {
            $search = mb_strtolower($filters->search);
            $filtered = array_values(array_filter(
                $filtered,
                fn (array $employee): bool => $this->matchesSearch($employee, $search),
            ));
        }

        if ($filters->summary !== '') {
            $filtered = array_values(array_filter(
                $filtered,
                fn (array $employee): bool => $this->matchesSummary($employee, $filters->summary),
            ));
        }

        return $filtered;
    }

    /**
     * @param  list<array<string, mixed>>  $employees
     * @return list<array<string, mixed>>
     */
    private function filterByDirectory(
        int $companyId,
        array $employees,
        CrewTimesheetPreparationReviewFilters $filters,
    ): array {
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

        return array_values(array_filter(
            $employees,
            fn (array $employee): bool => isset($matchingIds[(int) $employee['employee_id']]),
        ));
    }

    /**
     * @param  array<string, mixed>  $employee
     */
    private function matchesSummary(array $employee, string $summary): bool
    {
        return match ($summary) {
            CrewTimesheetPreparationReviewFilters::SUMMARY_SIGN_ON_STANDBY => (float) ($employee['sign_on_standby_days'] ?? 0) > 0,
            CrewTimesheetPreparationReviewFilters::SUMMARY_ONSITE => (float) ($employee['onsite_days'] ?? 0) > 0,
            CrewTimesheetPreparationReviewFilters::SUMMARY_SIGN_OFF_STANDBY => (float) ($employee['sign_off_standby_days'] ?? 0) > 0,
            CrewTimesheetPreparationReviewFilters::SUMMARY_BLOCKING => (int) ($employee['unresolved_blocking_warning_count'] ?? 0) > 0,
            CrewTimesheetPreparationReviewFilters::SUMMARY_INFORMATIONAL => (int) ($employee['informational_warning_count'] ?? 0) > 0,
            default => true,
        };
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
