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

    public const OWN_EMPLOYEE_ONLY_MESSAGE = 'You can only manage leave requests for your own employee record.';

    public function __construct(
        private int $companyId,
        private ?User $user,
    ) {}

    public static function make(int $companyId, ?User $user): self
    {
        return new self($companyId, $user);
    }

    public static function canSelfService(?User $user, int $companyId): bool
    {
        return self::eligibleSelfServiceEmployee($user, $companyId) !== null;
    }

    public static function eligibleSelfServiceEmployee(?User $user, int $companyId): ?Employee
    {
        if ($user === null || $companyId <= 0) {
            return null;
        }

        $employee = Employee::query()
            ->where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first(['id', 'company_id', 'department_id', 'status', 'user_id']);

        if ($employee === null) {
            return null;
        }

        if (! AttendanceLeaveDepartmentScope::canAccessEmployee($employee, $companyId)) {
            return null;
        }

        return $employee;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $employeeId = filter_var($value, FILTER_VALIDATE_INT);

        if ($employeeId === false || $employeeId <= 0 || $this->companyId <= 0 || $this->user === null) {
            $fail(self::INVALID_EMPLOYEE_MESSAGE);

            return;
        }

        if ($this->user->can('attendance.leave-requests.view_all')) {
            $this->validateViewAllEmployee($employeeId, $fail);

            return;
        }

        $this->validateSelfServiceEmployee($employeeId, $fail);
    }

    private function validateViewAllEmployee(int $employeeId, Closure $fail): void
    {
        $employee = Employee::query()
            ->whereKey($employeeId)
            ->where('company_id', $this->companyId)
            ->first(['id', 'company_id', 'department_id', 'status', 'user_id']);

        if ($employee === null || $employee->status !== 'active') {
            $fail(self::INVALID_EMPLOYEE_MESSAGE);

            return;
        }

        if (! EmployeeVisibilityScope::canAccess($this->user, $employee, $this->companyId)) {
            $fail(self::INVALID_EMPLOYEE_MESSAGE);

            return;
        }

        if (! AttendanceLeaveDepartmentScope::canAccessEmployee($employee, $this->companyId)) {
            $fail(AttendanceLeaveDepartmentScope::EXCLUDED_EMPLOYEE_MESSAGE);
        }
    }

    private function validateSelfServiceEmployee(int $employeeId, Closure $fail): void
    {
        $ownEmployee = Employee::withTrashed()
            ->where('company_id', $this->companyId)
            ->where('user_id', $this->user?->id)
            ->orderByRaw('deleted_at IS NULL DESC')
            ->first(['id', 'company_id', 'department_id', 'status', 'deleted_at', 'user_id']);

        if ($ownEmployee === null || $employeeId !== (int) $ownEmployee->id) {
            $fail(self::OWN_EMPLOYEE_ONLY_MESSAGE);

            return;
        }

        if ($ownEmployee->trashed() || $ownEmployee->status !== 'active') {
            $fail(self::INVALID_EMPLOYEE_MESSAGE);

            return;
        }

        if (! AttendanceLeaveDepartmentScope::canAccessEmployee($ownEmployee, $this->companyId)) {
            $fail(AttendanceLeaveDepartmentScope::EXCLUDED_EMPLOYEE_MESSAGE);
        }
    }
}
