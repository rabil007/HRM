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
            'settings.security.view',
            'settings.security.update',
            'settings.appearance.view',
            'settings.master-data.countries.view',
            'settings.master-data.countries.create',
            'settings.master-data.countries.update',
            'settings.master-data.countries.delete',
            'settings.master-data.currencies.view',
            'settings.master-data.currencies.create',
            'settings.master-data.currencies.update',
            'settings.master-data.currencies.delete',

            'settings.master-data.visa-types.view',
            'settings.master-data.visa-types.create',
            'settings.master-data.visa-types.update',
            'settings.master-data.visa-types.delete',

            'settings.master-data.religions.view',
            'settings.master-data.religions.create',
            'settings.master-data.religions.update',
            'settings.master-data.religions.delete',

            'settings.master-data.genders.view',
            'settings.master-data.genders.create',
            'settings.master-data.genders.update',
            'settings.master-data.genders.delete',

            'settings.master-data.banks.view',
            'settings.master-data.banks.create',
            'settings.master-data.banks.update',
            'settings.master-data.banks.delete',

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

            'employees.view',
            'employees.create',
            'employees.update',
            'employees.delete',
            'employees.export',

            'audit.view',
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
