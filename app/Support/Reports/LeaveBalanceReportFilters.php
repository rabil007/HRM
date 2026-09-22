<?php

namespace App\Support\Reports;

use App\Enums\LeaveTypeCategory;
use Illuminate\Http\Request;

final class LeaveBalanceReportFilters
{
    /**
     * @param  list<string>  $employeeStatuses
     */
    public function __construct(
        public readonly string $search = '',
        public readonly string $year = '',
        public readonly string $employeeId = '',
        public readonly string $departmentId = '',
        public readonly string $leaveTypeId = '',
        public readonly string $category = '',
        public readonly string $employeeStatus = '',
    ) {}

    public static function fromRequest(Request $request, int $defaultYear): self
    {
        $year = trim((string) $request->query('year', ''));

        if (! preg_match('/^\d{4}$/', $year)) {
            $year = (string) $defaultYear;
        }

        $category = trim((string) $request->query('category', ''));
        if (! in_array($category, LeaveTypeCategory::values(), true)) {
            $category = '';
        }

        $employeeStatus = trim((string) $request->query('employee_status', ''));
        if (! in_array($employeeStatus, self::employeeStatuses(), true)) {
            $employeeStatus = '';
        }

        return new self(
            search: trim((string) $request->query('search', '')),
            year: $year,
            employeeId: trim((string) $request->query('employee_id', '')),
            departmentId: trim((string) $request->query('department_id', '')),
            leaveTypeId: trim((string) $request->query('leave_type_id', '')),
            category: $category,
            employeeStatus: $employeeStatus,
        );
    }

    /**
     * @return list<string>
     */
    public static function employeeStatuses(): array
    {
        return ['active', 'inactive', 'on_leave', 'terminated'];
    }

    public static function employeeStatusLabel(string $status): string
    {
        return match ($status) {
            'active' => 'Active',
            'inactive' => 'Inactive',
            'on_leave' => 'On leave',
            'terminated' => 'Terminated',
            default => str($status)->replace('_', ' ')->title()->toString(),
        };
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'search' => $this->search,
            'year' => $this->year,
            'employee_id' => $this->employeeId,
            'department_id' => $this->departmentId,
            'leave_type_id' => $this->leaveTypeId,
            'category' => $this->category,
            'employee_status' => $this->employeeStatus,
        ];
    }
}
