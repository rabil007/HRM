<?php

namespace App\Support\Attendance;

use App\Models\Employee;
use App\Models\User;
use App\Support\Companies\ResolveCompanyAccess;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Single source of truth for whether an employee is an actionable leave approver.
 * Actionable requires: active employee, linked active user, accessible company
 * membership (active pivot or legacy no-pivot home), and both view + approve.
 */
final class LeaveApproverEligibility
{
    public function __construct(
        private PermissionRegistrar $permissionRegistrar,
        private ResolveCompanyAccess $companyAccess,
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
        $membershipByUserId = $this->companyAccess->accessibleMembershipByUserId($companyId, $userIds);
        $permissionByUserId = $employee->user !== null && $employee->user->status === 'active'
            ? $this->permissionsByUserId($companyId, collect([$employee->user]))
            : [];

        return $this->evaluateWithCaches($employee, $membershipByUserId, $permissionByUserId);
    }

    /**
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
        $collection = $employees instanceof EloquentCollection
            ? $employees
            : new EloquentCollection(collect($employees)->all());
        $collection->loadMissing('user:id,name,email,status');

        $membershipByUserId = $this->companyAccess->accessibleMembershipByUserId(
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
     * Batch-load team-scoped direct + role permissions without per-user can() queries.
     *
     * @param  Collection<int, User>  $users
     * @return array<int, array{view: bool, approve: bool}>
     */
    private function permissionsByUserId(int $companyId, Collection $users): array
    {
        if ($users->isEmpty()) {
            return [];
        }

        $userIds = $users->map(fn (User $user): int => (int) $user->id)->unique()->values()->all();
        $needed = [
            'attendance.leave-requests.view',
            'attendance.leave-requests.approve',
        ];

        $permissionTable = config('permission.table_names.permissions');
        $modelHasPermissions = config('permission.table_names.model_has_permissions');
        $modelHasRoles = config('permission.table_names.model_has_roles');
        $roleHasPermissions = config('permission.table_names.role_has_permissions');
        $teamKey = config('permission.column_names.team_foreign_key', 'company_id');

        $permissionIdsByName = DB::table($permissionTable)
            ->where('guard_name', 'web')
            ->whereIn('name', $needed)
            ->pluck('id', 'name')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $viewPermissionId = $permissionIdsByName['attendance.leave-requests.view'] ?? null;
        $approvePermissionId = $permissionIdsByName['attendance.leave-requests.approve'] ?? null;

        $map = [];
        foreach ($userIds as $userId) {
            $map[$userId] = ['view' => false, 'approve' => false];
        }

        if ($viewPermissionId === null && $approvePermissionId === null) {
            return $map;
        }

        $permissionIds = array_values(array_filter([$viewPermissionId, $approvePermissionId]));

        $direct = DB::table($modelHasPermissions)
            ->where($teamKey, $companyId)
            ->where('model_type', User::class)
            ->whereIn('model_id', $userIds)
            ->whereIn('permission_id', $permissionIds)
            ->get(['model_id', 'permission_id']);

        foreach ($direct as $row) {
            $userId = (int) $row->model_id;
            $permissionId = (int) $row->permission_id;

            if ($permissionId === $viewPermissionId) {
                $map[$userId]['view'] = true;
            }
            if ($permissionId === $approvePermissionId) {
                $map[$userId]['approve'] = true;
            }
        }

        $roleAssignments = DB::table($modelHasRoles)
            ->where($teamKey, $companyId)
            ->where('model_type', User::class)
            ->whereIn('model_id', $userIds)
            ->get(['model_id', 'role_id']);

        $roleIds = $roleAssignments->pluck('role_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();

        if ($roleIds === []) {
            return $map;
        }

        $rolePermissions = DB::table($roleHasPermissions)
            ->whereIn('role_id', $roleIds)
            ->whereIn('permission_id', $permissionIds)
            ->get(['role_id', 'permission_id']);

        $permissionsByRole = [];
        foreach ($rolePermissions as $row) {
            $permissionsByRole[(int) $row->role_id][] = (int) $row->permission_id;
        }

        foreach ($roleAssignments as $assignment) {
            $userId = (int) $assignment->model_id;
            $roleId = (int) $assignment->role_id;
            $rolePermissionIds = $permissionsByRole[$roleId] ?? [];

            if ($viewPermissionId !== null && in_array($viewPermissionId, $rolePermissionIds, true)) {
                $map[$userId]['view'] = true;
            }
            if ($approvePermissionId !== null && in_array($approvePermissionId, $rolePermissionIds, true)) {
                $map[$userId]['approve'] = true;
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
            $warnings[] = "{$name}'s linked user does not have accessible membership in this company.";
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
