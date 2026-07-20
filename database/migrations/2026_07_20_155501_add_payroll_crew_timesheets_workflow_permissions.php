<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * @var list<array{name: string, sources: list<string>}>
     */
    private const PERMISSIONS = [
        [
            'name' => 'payroll.crew_timesheets.submit',
            'sources' => [
                'payroll.crew_timesheets.prepare',
                'payroll.crew_timesheets.update',
            ],
        ],
        [
            'name' => 'payroll.crew_timesheets.approve',
            'sources' => [
                'payroll.periods.approve',
                'payroll.crew_timesheets.update',
            ],
        ],
        [
            'name' => 'payroll.crew_timesheets.return',
            'sources' => [
                'payroll.periods.approve',
                'payroll.crew_timesheets.update',
            ],
        ],
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::query()->firstOrCreate([
                'name' => $permission['name'],
                'guard_name' => 'web',
            ]);

            foreach ($permission['sources'] as $sourceName) {
                $this->grantFromSource($sourceName, $permission['name']);
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

        foreach (self::PERMISSIONS as $permission) {
            Permission::query()
                ->where('guard_name', 'web')
                ->where('name', $permission['name'])
                ->each(function (Permission $model) use ($roleHasPermissions, $modelHasPermissions, $pivotPermission): void {
                    DB::table($roleHasPermissions)->where($pivotPermission, $model->id)->delete();
                    DB::table($modelHasPermissions)->where($pivotPermission, $model->id)->delete();
                    $model->delete();
                });
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function grantFromSource(string $sourceName, string $targetName): void
    {
        $source = Permission::query()
            ->where('name', $sourceName)
            ->where('guard_name', 'web')
            ->first();

        $target = Permission::query()
            ->where('name', $targetName)
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
