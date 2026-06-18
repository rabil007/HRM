<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * @var array<string, list<string>>
     */
    private array $manageToGranular = [
        'crew_operations.deployments.manage' => [
            'crew_operations.deployments.create',
            'crew_operations.deployments.update',
            'crew_operations.deployments.delete',
            'crew_operations.deployments.export',
        ],
        'crew_operations.vessel_manning.manage' => [
            'crew_operations.vessel_manning.create',
            'crew_operations.vessel_manning.update',
            'crew_operations.vessel_manning.delete',
        ],
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $allNewPermissions = array_unique(array_merge(...array_values($this->manageToGranular)));

        foreach ($allNewPermissions as $name) {
            Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        $roleHasPermissions = config('permission.table_names.role_has_permissions');
        $pivotPermission = config('permission.column_names.permission_pivot_key') ?? 'permission_id';
        $pivotRole = config('permission.column_names.role_pivot_key') ?? 'role_id';

        foreach ($this->manageToGranular as $legacyName => $newNames) {
            $legacy = Permission::query()
                ->where('name', $legacyName)
                ->where('guard_name', 'web')
                ->first();

            if ($legacy === null) {
                continue;
            }

            $roleIds = DB::table($roleHasPermissions)
                ->where($pivotPermission, $legacy->id)
                ->pluck($pivotRole);

            $newPermissionIds = Permission::query()
                ->where('guard_name', 'web')
                ->whereIn('name', $newNames)
                ->pluck('id');

            foreach ($roleIds as $roleId) {
                foreach ($newPermissionIds as $permissionId) {
                    DB::table($roleHasPermissions)->insertOrIgnore([
                        $pivotPermission => $permissionId,
                        $pivotRole => $roleId,
                    ]);
                }
            }

            DB::table($roleHasPermissions)->where($pivotPermission, $legacy->id)->delete();
            $legacy->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_keys($this->manageToGranular) as $legacyName) {
            Permission::query()->firstOrCreate([
                'name' => $legacyName,
                'guard_name' => 'web',
            ]);
        }

        $roleHasPermissions = config('permission.table_names.role_has_permissions');
        $pivotPermission = config('permission.column_names.permission_pivot_key') ?? 'permission_id';
        $pivotRole = config('permission.column_names.role_pivot_key') ?? 'role_id';

        foreach ($this->manageToGranular as $legacyName => $newNames) {
            $legacy = Permission::query()
                ->where('name', $legacyName)
                ->where('guard_name', 'web')
                ->first();

            if ($legacy === null) {
                continue;
            }

            $newPermissionIds = Permission::query()
                ->where('guard_name', 'web')
                ->whereIn('name', $newNames)
                ->pluck('id');

            foreach ($newPermissionIds as $newPermissionId) {
                $roleIds = DB::table($roleHasPermissions)
                    ->where($pivotPermission, $newPermissionId)
                    ->pluck($pivotRole);

                foreach ($roleIds as $roleId) {
                    DB::table($roleHasPermissions)->insertOrIgnore([
                        $pivotPermission => $legacy->id,
                        $pivotRole => $roleId,
                    ]);
                }
            }

            DB::table($roleHasPermissions)->whereIn($pivotPermission, $newPermissionIds)->delete();

            Permission::query()
                ->where('guard_name', 'web')
                ->whereIn('name', $newNames)
                ->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
