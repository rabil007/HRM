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
        'documents.notification-routing.view' => [
            'label' => 'View Employee Document Expiry Notification Routing',
            'description' => 'Allows the user to view employee document expiry notification routing rules for the active company.',
        ],
        'documents.notification-routing.update' => [
            'label' => 'Manage Employee Document Expiry Notification Routing',
            'description' => 'Allows the user to create, update, enable, disable, and delete employee document expiry notification routing rules for the active company.',
        ],
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $now = now();
        $permissionIds = [];

        foreach (self::NEW_PERMISSIONS as $name => $metadata) {
            $permission = Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);

            $permission->forceFill($metadata)->save();
            $permissionIds[] = $permission->id;
        }

        $rolesTable = config('permission.table_names.roles');
        $roleHasPermissions = config('permission.table_names.role_has_permissions');
        $permissionsTable = config('permission.table_names.permissions');
        $pivotPermission = config('permission.column_names.permission_pivot_key') ?? 'permission_id';
        $pivotRole = config('permission.column_names.role_pivot_key') ?? 'role_id';

        $ownerRoleIds = DB::table($rolesTable)
            ->where('guard_name', 'web')
            ->where('name', 'Owner')
            ->pluck('id');

        foreach ($ownerRoleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table($roleHasPermissions)->insertOrIgnore([
                    $pivotPermission => $permissionId,
                    $pivotRole => $roleId,
                ]);
            }
        }

        // Grant to roles that already manage Document Types.
        $documentTypesUpdateId = DB::table($permissionsTable)
            ->where('guard_name', 'web')
            ->where('name', 'settings.master-data.document-types.update')
            ->value('id');

        $documentTypesViewId = DB::table($permissionsTable)
            ->where('guard_name', 'web')
            ->where('name', 'settings.master-data.document-types.view')
            ->value('id');

        $viewPermissionId = DB::table($permissionsTable)
            ->where('guard_name', 'web')
            ->where('name', 'documents.notification-routing.view')
            ->value('id');

        $updatePermissionId = DB::table($permissionsTable)
            ->where('guard_name', 'web')
            ->where('name', 'documents.notification-routing.update')
            ->value('id');

        if ($documentTypesViewId && $viewPermissionId) {
            $roleIds = DB::table($roleHasPermissions)
                ->where($pivotPermission, $documentTypesViewId)
                ->pluck($pivotRole);

            foreach ($roleIds as $roleId) {
                DB::table($roleHasPermissions)->insertOrIgnore([
                    $pivotPermission => $viewPermissionId,
                    $pivotRole => $roleId,
                ]);
            }
        }

        if ($documentTypesUpdateId && $updatePermissionId) {
            $roleIds = DB::table($roleHasPermissions)
                ->where($pivotPermission, $documentTypesUpdateId)
                ->pluck($pivotRole);

            foreach ($roleIds as $roleId) {
                DB::table($roleHasPermissions)->insertOrIgnore([
                    $pivotPermission => $updatePermissionId,
                    $pivotRole => $roleId,
                ]);

                if ($viewPermissionId) {
                    DB::table($roleHasPermissions)->insertOrIgnore([
                        $pivotPermission => $viewPermissionId,
                        $pivotRole => $roleId,
                    ]);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Preserve permissions; they may already be assigned in production.
    }
};
