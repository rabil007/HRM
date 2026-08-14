<?php

namespace App\Http\Requests\Attendance\Concerns;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Support\Employees\ActiveCompanyEmployeeRule;
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
            ? ActiveCompanyEmployeeRule::exists($companyId)
            : Rule::exists(Employee::class, 'id')->where(fn ($query) => $query->where('company_id', $companyId));

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
}
