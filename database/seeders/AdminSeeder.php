<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->orderBy('id')->first();

        if (! $company) {
            return;
        }

        $user = User::query()->updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin',
                'password' => 'password',
                'status' => 'active',
                'company_id' => $company->id,
            ]
        );

        $user->companies()->syncWithoutDetaching([
            $company->id => ['status' => 'active'],
        ]);

        $role = Role::query()->firstOrCreate([
            'company_id' => $company->id,
            'name' => 'Owner',
            'guard_name' => 'web',
        ]);

        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->pluck('name')
            ->all();

        $role->syncPermissions($permissions);

        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
        $user->syncRoles([$role->name]);
    }
}
