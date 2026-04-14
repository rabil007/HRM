<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
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

    $role = Role::query()->firstOrCreate([
        'company_id' => $company->id,
        'name' => 'test-role',
        'guard_name' => 'web',
    ]);
    $role->syncPermissions($permissions);

    $user->syncRoles([$role]);
}
