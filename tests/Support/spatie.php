<?php

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Support\Employees\EmployeeVisibilityScope;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function grantCompanyPermissions(User $user, Company $company, array $permissionNames): void
{
    DB::table('company_user')->updateOrInsert(
        ['company_id' => $company->id, 'user_id' => $user->id],
        ['status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    );

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

    $permissions = [];
    foreach ($permissionNames as $name) {
        $permissions[] = Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }

    $role = Role::query()->firstOrCreate(
        [
            'company_id' => $company->id,
            'name' => 'test-role',
            'guard_name' => 'web',
        ],
        [
            'employee_visibility_scope' => Role::SCOPE_ALL,
        ],
    );
    $role->syncPermissions($permissions);

    $user->syncRoles([$role]);
}

/**
 * @param  list<int>  $departmentIds
 */
function restrictTestRoleEmployeeVisibility(User $user, Company $company, array $departmentIds): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

    $role = $user->roles()
        ->where('spatie_roles.company_id', $company->id)
        ->where('spatie_roles.name', 'test-role')
        ->first();

    if ($role === null) {
        return;
    }

    $role->update([
        'employee_visibility_scope' => Role::SCOPE_SELECTED_DEPARTMENTS,
    ]);

    $syncData = [];
    foreach ($departmentIds as $departmentId) {
        $syncData[(int) $departmentId] = ['company_id' => $company->id];
    }

    $role->employeeVisibilityDepartments()->sync($syncData);
    EmployeeVisibilityScope::clearCache();
}
