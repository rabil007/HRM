<?php

namespace App\Support\Attendance;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\PermissionRegistrar;

final class PresentLeaveApproverOption
{
    public function __construct(
        private PermissionRegistrar $permissionRegistrar,
    ) {}

    /**
     * @return list<array{
     *     id: int,
     *     employee_no: string|null,
     *     name: string|null,
     *     employee_status: string|null,
     *     has_linked_user: bool,
     *     linked_user_active: bool,
     *     has_leave_request_approve_permission: bool,
     *     actionable: bool,
     *     warnings: list<string>,
     * }>
     */
    public function forCompany(int $companyId, bool $activeOnly = true): array
    {
        $employees = Employee::query()
            ->where('company_id', $companyId)
            ->when($activeOnly, fn ($query) => $query->where('status', 'active'))
            ->with('user:id,name,email,status')
            ->orderBy('name')
            ->get(['id', 'employee_no', 'name', 'status', 'user_id']);

        return $employees
            ->map(fn (Employee $employee) => $this->present($employee, $companyId))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Employee>|iterable<int, Employee>  $employees
     * @return list<array{
     *     id: int,
     *     employee_no: string|null,
     *     name: string|null,
     *     employee_status: string|null,
     *     has_linked_user: bool,
     *     linked_user_active: bool,
     *     has_leave_request_approve_permission: bool,
     *     actionable: bool,
     *     warnings: list<string>,
     * }>
     */
    public function presentMany(iterable $employees, int $companyId): array
    {
        return collect($employees)
            ->map(fn (Employee $employee) => $this->present($employee, $companyId))
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     id: int,
     *     employee_no: string|null,
     *     name: string|null,
     *     employee_status: string|null,
     *     has_linked_user: bool,
     *     linked_user_active: bool,
     *     has_leave_request_approve_permission: bool,
     *     actionable: bool,
     *     warnings: list<string>,
     * }
     */
    public function present(Employee $employee, int $companyId): array
    {
        $employee->loadMissing('user:id,name,email,status');

        $user = $employee->user;
        $hasLinkedUser = $user !== null;
        $linkedUserActive = $hasLinkedUser && $user->status === 'active';
        $hasApprovePermission = $hasLinkedUser
            ? $this->userHasApprovePermission($user, $companyId)
            : false;
        $employeeActive = $employee->status === 'active';
        $actionable = $employeeActive && $linkedUserActive && $hasApprovePermission;

        return [
            'id' => (int) $employee->id,
            'employee_no' => $employee->employee_no,
            'name' => $employee->name,
            'employee_status' => $employee->status,
            'has_linked_user' => $hasLinkedUser,
            'linked_user_active' => $linkedUserActive,
            'has_leave_request_approve_permission' => $hasApprovePermission,
            'actionable' => $actionable,
            'warnings' => $this->warnings(
                employee: $employee,
                employeeActive: $employeeActive,
                hasLinkedUser: $hasLinkedUser,
                linkedUserActive: $linkedUserActive,
                hasApprovePermission: $hasApprovePermission,
            ),
        ];
    }

    /**
     * @return list<string>
     */
    private function warnings(
        Employee $employee,
        bool $employeeActive,
        bool $hasLinkedUser,
        bool $linkedUserActive,
        bool $hasApprovePermission,
    ): array {
        $name = (string) ($employee->name ?? 'Selected employee');
        $warnings = [];

        if (! $employeeActive) {
            $warnings[] = "{$name} is not an active employee.";
        }

        if (! $hasLinkedUser) {
            $warnings[] = "{$name} is not linked to a user account.";

            return $warnings;
        }

        if (! $linkedUserActive) {
            $warnings[] = "{$name} is linked to an inactive user.";
        }

        if (! $hasApprovePermission) {
            $warnings[] = "{$name}'s linked user does not have leave-request approve permission. Grant it manually — it is not auto-assigned.";
        }

        return $warnings;
    }

    private function userHasApprovePermission(User $user, int $companyId): bool
    {
        $previousTeamId = $this->permissionRegistrar->getPermissionsTeamId();

        try {
            $this->permissionRegistrar->setPermissionsTeamId($companyId);
            $user->unsetRelation('roles')->unsetRelation('permissions');

            return $user->can('attendance.leave-requests.approve');
        } finally {
            $this->permissionRegistrar->setPermissionsTeamId($previousTeamId);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }
}
