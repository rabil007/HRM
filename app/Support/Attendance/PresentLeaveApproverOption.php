<?php

namespace App\Support\Attendance;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

final class PresentLeaveApproverOption
{
    public function __construct(
        private PermissionRegistrar $permissionRegistrar,
    ) {}

    /**
     * @param  list<int>|null  $includeEmployeeIds  Always include these employees (e.g. selected inactive)
     * @return list<array{
     *     id: int,
     *     employee_no: string|null,
     *     name: string|null,
     *     employee_status: string|null,
     *     has_linked_user: bool,
     *     linked_user_active: bool,
     *     has_active_company_membership: bool,
     *     has_leave_request_approve_permission: bool,
     *     actionable: bool,
     *     warnings: list<string>,
     * }>
     */
    public function forCompany(int $companyId, bool $activeOnly = true, ?array $includeEmployeeIds = null): array
    {
        $includeEmployeeIds = collect($includeEmployeeIds ?? [])
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $employees = Employee::query()
            ->where('company_id', $companyId)
            ->when($activeOnly && $includeEmployeeIds === [], fn ($query) => $query->where('status', 'active'))
            ->when($activeOnly && $includeEmployeeIds !== [], function ($query) use ($includeEmployeeIds): void {
                $query->where(function ($inner) use ($includeEmployeeIds): void {
                    $inner->where('status', 'active')
                        ->orWhereIn('id', $includeEmployeeIds);
                });
            })
            ->with('user:id,name,email,status')
            ->orderBy('name')
            ->get(['id', 'employee_no', 'name', 'status', 'user_id']);

        $membershipByUserId = $this->activeMembershipByUserId(
            $companyId,
            $employees->pluck('user_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all(),
        );

        $permissionByUserId = $this->approvePermissionByUserId(
            $companyId,
            $employees
                ->filter(fn (Employee $employee): bool => $employee->user !== null && $employee->user->status === 'active')
                ->map(fn (Employee $employee): User => $employee->user)
                ->unique('id')
                ->values(),
        );

        return $employees
            ->map(fn (Employee $employee) => $this->presentWithCaches(
                $employee,
                $companyId,
                $membershipByUserId,
                $permissionByUserId,
            ))
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
     *     has_active_company_membership: bool,
     *     has_leave_request_approve_permission: bool,
     *     actionable: bool,
     *     warnings: list<string>,
     * }>
     */
    public function presentMany(iterable $employees, int $companyId): array
    {
        $collection = collect($employees);
        $collection->each(fn (Employee $employee) => $employee->loadMissing('user:id,name,email,status'));

        $membershipByUserId = $this->activeMembershipByUserId(
            $companyId,
            $collection->pluck('user_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all(),
        );

        $permissionByUserId = $this->approvePermissionByUserId(
            $companyId,
            $collection
                ->filter(fn (Employee $employee): bool => $employee->user !== null && $employee->user->status === 'active')
                ->map(fn (Employee $employee): User => $employee->user)
                ->unique('id')
                ->values(),
        );

        return $collection
            ->map(fn (Employee $employee) => $this->presentWithCaches(
                $employee,
                $companyId,
                $membershipByUserId,
                $permissionByUserId,
            ))
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
     *     has_active_company_membership: bool,
     *     has_leave_request_approve_permission: bool,
     *     actionable: bool,
     *     warnings: list<string>,
     * }
     */
    public function present(Employee $employee, int $companyId): array
    {
        $employee->loadMissing('user:id,name,email,status');

        $userIds = $employee->user_id !== null ? [(int) $employee->user_id] : [];
        $membershipByUserId = $this->activeMembershipByUserId($companyId, $userIds);
        $permissionByUserId = $employee->user !== null && $employee->user->status === 'active'
            ? $this->approvePermissionByUserId($companyId, collect([$employee->user]))
            : [];

        return $this->presentWithCaches($employee, $companyId, $membershipByUserId, $permissionByUserId);
    }

    /**
     * @param  array<int, bool>  $membershipByUserId
     * @param  array<int, bool>  $permissionByUserId
     * @return array{
     *     id: int,
     *     employee_no: string|null,
     *     name: string|null,
     *     employee_status: string|null,
     *     has_linked_user: bool,
     *     linked_user_active: bool,
     *     has_active_company_membership: bool,
     *     has_leave_request_approve_permission: bool,
     *     actionable: bool,
     *     warnings: list<string>,
     * }
     */
    private function presentWithCaches(
        Employee $employee,
        int $companyId,
        array $membershipByUserId,
        array $permissionByUserId,
    ): array {
        $user = $employee->user;
        $hasLinkedUser = $user !== null;
        $linkedUserActive = $hasLinkedUser && $user->status === 'active';
        $hasActiveMembership = $hasLinkedUser
            && ($membershipByUserId[(int) $user->id] ?? false);
        $hasApprovePermission = $hasLinkedUser
            ? ($permissionByUserId[(int) $user->id] ?? false)
            : false;
        $employeeActive = $employee->status === 'active';
        $actionable = $employeeActive
            && $linkedUserActive
            && $hasActiveMembership
            && $hasApprovePermission;

        return [
            'id' => (int) $employee->id,
            'employee_no' => $employee->employee_no,
            'name' => $employee->name,
            'employee_status' => $employee->status,
            'has_linked_user' => $hasLinkedUser,
            'linked_user_active' => $linkedUserActive,
            'has_active_company_membership' => $hasActiveMembership,
            'has_leave_request_approve_permission' => $hasApprovePermission,
            'actionable' => $actionable,
            'warnings' => $this->warnings(
                employee: $employee,
                employeeActive: $employeeActive,
                hasLinkedUser: $hasLinkedUser,
                linkedUserActive: $linkedUserActive,
                hasActiveMembership: $hasActiveMembership,
                hasApprovePermission: $hasApprovePermission,
            ),
        ];
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, bool>
     */
    private function activeMembershipByUserId(int $companyId, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $activeIds = DB::table('company_user')
            ->where('company_id', $companyId)
            ->whereIn('user_id', $userIds)
            ->where('status', 'active')
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $map = [];
        foreach ($userIds as $userId) {
            $map[$userId] = in_array($userId, $activeIds, true);
        }

        return $map;
    }

    /**
     * @param  Collection<int, User>  $users
     * @return array<int, bool>
     */
    private function approvePermissionByUserId(int $companyId, Collection $users): array
    {
        if ($users->isEmpty()) {
            return [];
        }

        $previousTeamId = $this->permissionRegistrar->getPermissionsTeamId();
        $map = [];

        try {
            $this->permissionRegistrar->setPermissionsTeamId($companyId);

            foreach ($users as $user) {
                $user->unsetRelation('roles')->unsetRelation('permissions');
                $map[(int) $user->id] = $user->can('attendance.leave-requests.approve');
            }
        } finally {
            $this->permissionRegistrar->setPermissionsTeamId($previousTeamId);

            foreach ($users as $user) {
                $user->unsetRelation('roles')->unsetRelation('permissions');
            }
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    private function warnings(
        Employee $employee,
        bool $employeeActive,
        bool $hasLinkedUser,
        bool $linkedUserActive,
        bool $hasActiveMembership,
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

        if (! $hasActiveMembership) {
            $warnings[] = "{$name}'s linked user does not have active membership in this company.";
        }

        if (! $hasApprovePermission) {
            $warnings[] = "{$name}'s linked user does not have leave-request approve permission. Grant it manually — it is not auto-assigned.";
        }

        return $warnings;
    }
}
