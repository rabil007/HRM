<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSION = 'payroll.crew_timesheets.apply_approved';

    /**
     * @var list<string>
     */
    private const SOURCES = [
        'payroll.crew_timesheets.update',
        'payroll.crew_timesheets.create',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()->firstOrCreate([
            'name' => self::PERMISSION,
            'guard_name' => 'web',
        ]);

        foreach (self::SOURCES as $sourceName) {
            $this->grantFromSource($sourceName);
        }

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
            ->where('name', self::PERMISSION)
            ->each(function (Permission $permission) use ($roleHasPermissions, $modelHasPermissions, $pivotPermission): void {
                DB::table($roleHasPermissions)->where($pivotPermission, $permission->id)->delete();
                DB::table($modelHasPermissions)->where($pivotPermission, $permission->id)->delete();
                $permission->delete();
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function grantFromSource(string $sourceName): void
    {
        $source = Permission::query()
            ->where('name', $sourceName)
            ->where('guard_name', 'web')
            ->first();

        $target = Permission::query()
            ->where('name', self::PERMISSION)
            ->where('guard_name', 'web')
            ->first();

        if ($source === null || $target === null) {
            return;
        }

        $roleHasPermissions = config('permission.table_names.role_has_permissions');
        $pivotPermission = config('permission.column_names.permission_pivot_key') ?? 'permission_id';
        $pivotRole = config('permission.column_names.role_pivot_key') ?? 'role_id';
        $roleIds = DB::table($roleHasPermissions)
            ->where($pivotPermission, $source->id)
            ->pluck($pivotRole);

        foreach ($roleIds as $roleId) {
            DB::table($roleHasPermissions)->insertOrIgnore([
                $pivotPermission => $target->id,
                $pivotRole => $roleId,
            ]);
        }
    }
};
