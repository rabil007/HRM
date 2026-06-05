<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissionNames = [
            'hikvision.persons.view',
            'hikvision.persons.sync',
            'hikvision.devices.view',
            'hikvision.devices.sync',
            'hikvision.events.view',
            'hikvision.events.fetch',
        ];

        $permissionIds = [];

        foreach ($permissionNames as $name) {
            $permission = Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);

            $permissionIds[] = $permission->id;
        }

        $rolesTable = config('permission.table_names.roles');
        $roleHasPermissions = config('permission.table_names.role_has_permissions');
        $pivotPermission = config('permission.column_names.permission_pivot_key') ?? 'permission_id';
        $pivotRole = config('permission.column_names.role_pivot_key') ?? 'role_id';

        $ownerRoleIds = DB::table($rolesTable)
            ->where('name', 'Owner')
            ->where('guard_name', 'web')
            ->pluck('id');

        foreach ($ownerRoleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table($roleHasPermissions)->insertOrIgnore([
                    $pivotPermission => $permissionId,
                    $pivotRole => $roleId,
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()
            ->whereIn('name', [
                'hikvision.persons.view',
                'hikvision.persons.sync',
                'hikvision.devices.view',
                'hikvision.devices.sync',
                'hikvision.events.view',
                'hikvision.events.fetch',
            ])
            ->where('guard_name', 'web')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
