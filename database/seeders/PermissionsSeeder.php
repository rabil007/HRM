<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'companies.view',
            'companies.create',
            'companies.update',
            'companies.delete',
            'companies.export',

            'branches.view',
            'branches.create',
            'branches.update',
            'branches.delete',
            'branches.export',

            'departments.view',
            'departments.create',
            'departments.update',
            'departments.delete',
            'departments.export',

            'positions.view',
            'positions.create',
            'positions.update',
            'positions.delete',
            'positions.export',

            'roles.view',
            'roles.create',
            'roles.update',
            'roles.delete',
            'roles.export',

            'users.view',
            'users.create',
            'users.update',
            'users.delete',
            'users.export',
        ];

        foreach ($permissions as $name) {
            Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
