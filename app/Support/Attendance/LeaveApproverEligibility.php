<?php

namespace App\Support\Attendance;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Single source of truth for whether an employee is an actionable leave approver.
 * Actionable requires: active employee, linked active user, active company
 * membership, and both the view and approve leave-request permissions.
 */
final class LeaveApproverEligibility
{
    public function __construct(
        private PermissionRegistrar $permissionRegistrar,
    ) {}

    /**
     * @return array{
     *     employee_active: bool,
     *     has_linked_user: bool,
     *     linked_user_active: bool,
     *     has_active_company_membership: bool,
     *     has_view_permission: bool,
     *     has_approve_permission: bool,
     *     actionable: bool,
     *     warnings: list<string>,
     * }
     */
    public function evaluate(Employee $employee, int $companyId): array
    {
        $employee->loadMissing('user:id,name,email,status');

        $userIds = $employee->user_id !== null ? [(int) $employee->user_id] : [];
        $membershipByUserId = $this->activeMembershipByUserId($companyId, $userIds);
        $permissionByUserId = $employee->user !== null && $employee->user->status === 'active'
            ? $this->permissionsByUserId($companyId, collect([$employee->user]))
            : [];

        return $this->evaluateWithCaches($employee, $membershipByUserId, $permissionByUserId);
    }

    /**
     * Batch evaluation to avoid N+1 queries when presenting many employees.
     *
     * @param  iterable<int, Employee>  $employees
     * @return array<int, array{
     *     employee_active: bool,
     *     has_linked_user: bool,
     *     linked_user_active: bool,
     *     has_active_company_membership: bool,
     *     has_view_permission: bool,
     *     has_approve_permission: bool,
     *     actionable: bool,
     *     warnings: list<string>,
     * }>
     */
    public function evaluateMany(iterable $employees, int $companyId): array
    {
        $collection = collect($employees);
        $collection->each(fn (Employee $employee) => $employee->loadMissing('user:id,name,email,status'));

        $membershipByUserId = $this->activeMembershipByUserId(
            $companyId,
            $collection->pluck('user_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all(),
        );

        $permissionByUserId = $this->permissionsByUserId(
            $companyId,
            $collection
                ->filter(fn (Employee $employee): bool => $employee->user !== null && $employee->user->status === 'active')
                ->map(fn (Employee $employee): User => $employee->user)
                ->unique('id')
                ->values(),
        );

        $evaluations = [];

        foreach ($collection as $employee) {
            $evaluations[(int) $employee->id] = $this->evaluateWithCaches($employee, $membershipByUserId, $permissionByUserId);
        }

        return $evaluations;
    }

    /**
     * @param  array<int, bool>  $membershipByUserId
     * @param  array<int, array{view: bool, approve: bool}>  $permissionByUserId
     * @return array{
     *     employee_active: bool,
     *     has_linked_user: bool,
     *     linked_user_active: bool,
     *     has_active_company_membership: bool,
     *     has_view_permission: bool,
     *     has_approve_permission: bool,
     *     actionable: bool,
     *     warnings: list<string>,
     * }
     */
    private function evaluateWithCaches(Employee $employee, array $membershipByUserId, array $permissionByUserId): array
    {
        $user = $employee->user;
        $employeeActive = $employee->status === 'active';
        $hasLinkedUser = $user !== null;
        $linkedUserActive = $hasLinkedUser && $user->status === 'active';
        $hasActiveMembership = $hasLinkedUser && ($membershipByUserId[(int) $user->id] ?? false);
        $permissions = $hasLinkedUser
            ? ($permissionByUserId[(int) $user->id] ?? ['view' => false, 'approve' => false])
            : ['view' => false, 'approve' => false];
        $hasViewPermission = $permissions['view'];
        $hasApprovePermission = $permissions['approve'];

        $actionable = $employeeActive
            && $linkedUserActive
            && $hasActiveMembership
            && $hasViewPermission
            && $hasApprovePermission;

        return [
            'employee_active' => $employeeActive,
            'has_linked_user' => $hasLinkedUser,
            'linked_user_active' => $linkedUserActive,
            'has_active_company_membership' => $hasActiveMembership,
            'has_view_permission' => $hasViewPermission,
            'has_approve_permission' => $hasApprovePermission,
            'actionable' => $actionable,
            'warnings' => $this->warnings(
                employee: $employee,
                employeeActive: $employeeActive,
                hasLinkedUser: $hasLinkedUser,
                linkedUserActive: $linkedUserActive,
                hasActiveMembership: $hasActiveMembership,
                hasViewPermission: $hasViewPermission,
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
     * @return array<int, array{view: bool, approve: bool}>
     */
    private function permissionsByUserId(int $companyId, Collection $users): array
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
                $map[(int) $user->id] = [
                    'view' => $user->can('attendance.leave-requests.view'),
                    'approve' => $user->can('attendance.leave-requests.approve'),
                ];
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
        bool $hasViewPermission,
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

        if (! $hasViewPermission) {
            $warnings[] = "{$name}'s linked user does not have leave-request view permission. Grant it manually — it is not auto-assigned.";
        }

        if (! $hasApprovePermission) {
            $warnings[] = "{$name}'s linked user does not have leave-request approve permission. Grant it manually — it is not auto-assigned.";
        }

        return $warnings;
    }
}
