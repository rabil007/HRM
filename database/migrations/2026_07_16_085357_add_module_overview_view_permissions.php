<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const NEW_PERMISSIONS = [
        'attendance.overview.view',
        'payroll.overview.view',
    ];

    /**
     * Source sibling permissions that previously unlocked Overview access.
     *
     * @var array<string, list<string>>
     */
    private const GRANT_FROM = [
        'attendance.overview.view' => [
            'attendance.records.view',
            'attendance.leave-requests.view',
        ],
        'payroll.overview.view' => [
            'payroll.periods.view',
            'payroll.crew_timesheets.view',
        ],
        'crew_operations.overview.view' => [
            'crew_operations.deployments.view',
        ],
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

        Permission::query()->firstOrCreate([
            'name' => 'crew_operations.overview.view',
            'guard_name' => 'web',
        ]);

        $roleHasPermissions = config('permission.table_names.role_has_permissions');
        $pivotPermission = config('permission.column_names.permission_pivot_key') ?? 'permission_id';
        $pivotRole = config('permission.column_names.role_pivot_key') ?? 'role_id';

        foreach (self::GRANT_FROM as $overviewName => $sourceNames) {
            $overview = Permission::query()
                ->where('name', $overviewName)
                ->where('guard_name', 'web')
                ->first();

            if ($overview === null) {
                continue;
            }

            $sourceIds = Permission::query()
                ->where('guard_name', 'web')
                ->whereIn('name', $sourceNames)
                ->pluck('id');

            if ($sourceIds->isEmpty()) {
                continue;
            }

            $roleIds = DB::table($roleHasPermissions)
                ->whereIn($pivotPermission, $sourceIds)
                ->distinct()
                ->pluck($pivotRole);

            foreach ($roleIds as $roleId) {
                DB::table($roleHasPermissions)->insertOrIgnore([
                    $pivotPermission => $overview->id,
                    $pivotRole => $roleId,
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $roleHasPermissions = config('permission.table_names.role_has_permissions');
        $modelHasPermissions = config('permission.table_names.model_has_permissions');
        $pivotPermission = config('permission.column_names.permission_pivot_key') ?? 'permission_id';

        foreach (self::NEW_PERMISSIONS as $name) {
            $permission = Permission::query()
                ->where('name', $name)
                ->where('guard_name', 'web')
                ->first();

            if ($permission === null) {
                continue;
            }

            DB::table($roleHasPermissions)
                ->where($pivotPermission, $permission->id)
                ->delete();

            DB::table($modelHasPermissions)
                ->where($pivotPermission, $permission->id)
                ->delete();

            $permission->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
