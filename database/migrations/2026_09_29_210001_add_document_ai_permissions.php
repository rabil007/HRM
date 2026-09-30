<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * @var array<string, array{label: string, description: string}>
     */
    private const NEW_PERMISSIONS = [
        'documents.ai.use' => [
            'label' => 'Use Document AI',
            'description' => 'Allows the user to use enabled Document AI assistance for employee document intake in the active company.',
        ],
        'documents.ai.manage' => [
            'label' => 'Manage Document AI',
            'description' => 'Allows the user to configure the company Document AI mode without changing platform AI provider credentials.',
        ],
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::NEW_PERMISSIONS as $name => $metadata) {
            $permission = Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);

            $permission->forceFill($metadata)->save();
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
            ->whereIn('name', array_keys(self::NEW_PERMISSIONS))
            ->each(function (Permission $permission) use ($roleHasPermissions, $modelHasPermissions, $pivotPermission): void {
                DB::table($roleHasPermissions)->where($pivotPermission, $permission->id)->delete();
                DB::table($modelHasPermissions)->where($pivotPermission, $permission->id)->delete();
                $permission->delete();
            });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
