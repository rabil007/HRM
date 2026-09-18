<?php

namespace App\Support\Reports;

use Illuminate\Http\Request;

final class LeaveReportFilters
{
    public function __construct(
        public readonly string $search = '',
        public readonly string $leaveFrom = '',
        public readonly string $leaveTo = '',
        public readonly string $employeeId = '',
        public readonly string $leaveTypeId = '',
        public readonly string $status = '',
        public readonly string $departmentId = '',
        public readonly string $submittedFrom = '',
        public readonly string $submittedTo = '',
        public readonly string $decidedFrom = '',
        public readonly string $decidedTo = '',
        public readonly string $sort = 'start_date',
        public readonly string $direction = 'desc',
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            search: trim((string) $request->query('search', '')),
            leaveFrom: (string) $request->query('leave_from', ''),
            leaveTo: (string) $request->query('leave_to', ''),
            employeeId: (string) $request->query('employee_id', ''),
            leaveTypeId: (string) $request->query('leave_type_id', ''),
            status: (string) $request->query('status', ''),
            departmentId: (string) $request->query('department_id', ''),
            submittedFrom: (string) $request->query('submitted_from', ''),
            submittedTo: (string) $request->query('submitted_to', ''),
            decidedFrom: (string) $request->query('decided_from', ''),
            decidedTo: (string) $request->query('decided_to', ''),
            sort: (string) $request->query('sort', 'start_date'),
            direction: strtolower((string) $request->query('direction', 'desc')) === 'asc' ? 'asc' : 'desc',
        );
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'search' => $this->search,
            'leave_from' => $this->leaveFrom,
            'leave_to' => $this->leaveTo,
            'employee_id' => $this->employeeId,
            'leave_type_id' => $this->leaveTypeId,
            'status' => $this->status,
            'department_id' => $this->departmentId,
            'submitted_from' => $this->submittedFrom,
            'submitted_to' => $this->submittedTo,
            'decided_from' => $this->decidedFrom,
            'decided_to' => $this->decidedTo,
            'sort' => $this->sort,
            'direction' => $this->direction,
        ];
    }
}
