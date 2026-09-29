<?php

namespace App\Support\Attendance;

use App\Models\Employee;
use App\Models\User;
use App\Support\Employees\EmployeeVisibilityScope;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class LeaveRequestEligibleEmployeeRule implements ValidationRule
{
    private const INVALID_EMPLOYEE_MESSAGE = 'The selected employee is invalid or inactive for this company.';

    public function __construct(
        private int $companyId,
        private ?User $user,
    ) {}

    public static function make(int $companyId, ?User $user): self
    {
        return new self($companyId, $user);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $employeeId = filter_var($value, FILTER_VALIDATE_INT);

        if ($employeeId === false || $employeeId <= 0 || $this->companyId <= 0 || $this->user === null) {
            $fail(self::INVALID_EMPLOYEE_MESSAGE);

            return;
        }

        $employee = Employee::query()
            ->whereKey($employeeId)
            ->where('company_id', $this->companyId)
            ->first(['id', 'company_id', 'department_id', 'status', 'user_id']);

        if ($employee === null || $employee->status !== 'active') {
            $fail(self::INVALID_EMPLOYEE_MESSAGE);

            return;
        }

        if ($this->user->can('attendance.leave-requests.view_all')) {
            $this->validateViewAllEmployee($employee, $fail);

            return;
        }

        $this->validateSelfServiceEmployee($employee, $fail);
    }

    private function validateViewAllEmployee(Employee $employee, Closure $fail): void
    {
        if (! EmployeeVisibilityScope::canAccess($this->user, $employee, $this->companyId)) {
            $fail(self::INVALID_EMPLOYEE_MESSAGE);

            return;
        }

        if (! AttendanceLeaveDepartmentScope::canAccessEmployee($employee, $this->companyId)) {
            $fail(AttendanceLeaveDepartmentScope::EXCLUDED_EMPLOYEE_MESSAGE);
        }
    }

    private function validateSelfServiceEmployee(Employee $employee, Closure $fail): void
    {
        if ((int) $employee->user_id !== (int) $this->user?->id) {
            $fail('You can only manage leave requests for your own employee record.');

            return;
        }

        if (! AttendanceLeaveDepartmentScope::canAccessEmployee($employee, $this->companyId)) {
            $fail(AttendanceLeaveDepartmentScope::EXCLUDED_EMPLOYEE_MESSAGE);
        }
    }
}
