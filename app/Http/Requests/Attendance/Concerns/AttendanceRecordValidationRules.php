<?php

namespace App\Http\Requests\Attendance\Concerns;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Support\Attendance\AttendanceLeaveDepartmentScope;
use App\Support\Employees\AttendanceLeaveEligibleEmployeeRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait AttendanceRecordValidationRules
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function attendanceRecordFieldRules(?int $recordId = null, bool $requireActiveEmployee = true): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        $employeeRule = $requireActiveEmployee
            ? AttendanceLeaveEligibleEmployeeRule::exists($companyId, $this->user())
            : Rule::exists(Employee::class, 'id')->where(fn ($query) => $query
                ->where('company_id', $companyId)
                ->whereIn('department_id', AttendanceLeaveDepartmentScope::includedDepartmentIdsSubquery($companyId)));

        return [
            'employee_id' => [
                'required',
                'integer',
                $employeeRule,
            ],
            'date' => ['required', 'date'],
            'clock_in' => ['nullable', 'date'],
            'clock_out' => ['nullable', 'date', 'after_or_equal:clock_in'],
            'hours_worked' => ['nullable', 'numeric', 'min:0'],
            'overtime_hours' => ['nullable', 'numeric', 'min:0'],
            'late_minutes' => ['nullable', 'integer', 'min:0'],
            'status' => ['required', Rule::in(AttendanceRecord::statusOptions())],
            'source' => ['sometimes', Rule::in(AttendanceRecord::sourceOptions())],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function attendanceRecordFieldMessages(): array
    {
        return [
            'employee_id.exists' => AttendanceLeaveDepartmentScope::EXCLUDED_EMPLOYEE_MESSAGE,
        ];
    }
}
