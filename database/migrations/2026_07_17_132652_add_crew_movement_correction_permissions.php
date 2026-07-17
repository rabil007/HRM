<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const NEW_PERMISSIONS = [
        'crew_operations.corrections.view',
        'crew_operations.corrections.request',
        'crew_operations.corrections.approve',
        'crew_operations.corrections.override',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::NEW_PERMISSIONS as $name) {
            Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        $this->grantFromSource(
            'crew_operations.assignments.view',
            ['crew_operations.corrections.view'],
        );
        $this->grantFromSource(
            'crew_operations.movements.perform',
            ['crew_operations.corrections.request'],
        );
        $this->grantFromSource(
            'crew_operations.assignments.update',
            ['crew_operations.corrections.approve'],
        );
        $this->grantFromSource(
            'roles.update',
            ['crew_operations.corrections.override'],
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $roleHasPermissions = config('permission.table_names.role_has_permissions');
        $modelHasPermissions = config('permission.table_names.model_has_permissions');
        $pivotPermission = config('permission.column_names.permission_pivot_key') ?? 'permission_id';

        Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', self::NEW_PERMISSIONS)
            ->each(function (Permission $permission) use ($roleHasPermissions, $modelHasPermissions, $pivotPermission): void {
                DB::table($roleHasPermissions)->where($pivotPermission, $permission->id)->delete();
                DB::table($modelHasPermissions)->where($pivotPermission, $permission->id)->delete();
                $permission->delete();
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param  list<string>  $targets
     */
    private function grantFromSource(string $sourceName, array $targets): void
    {
        $source = Permission::query()
            ->where('name', $sourceName)
            ->where('guard_name', 'web')
            ->first();

        if ($source === null) {
            return;
        }

        $roleHasPermissions = config('permission.table_names.role_has_permissions');
        $pivotPermission = config('permission.column_names.permission_pivot_key') ?? 'permission_id';
        $pivotRole = config('permission.column_names.role_pivot_key') ?? 'role_id';
        $roleIds = DB::table($roleHasPermissions)
            ->where($pivotPermission, $source->id)
            ->pluck($pivotRole);

        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $targets)
            ->get();

        foreach ($roleIds as $roleId) {
            foreach ($permissions as $permission) {
                DB::table($roleHasPermissions)->insertOrIgnore([
                    $pivotPermission => $permission->id,
                    $pivotRole => $roleId,
                ]);
            }
        }
    }
};
