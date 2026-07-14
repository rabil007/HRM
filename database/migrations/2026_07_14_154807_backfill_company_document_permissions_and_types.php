<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $now = now();
        $permissionNames = [
            'company_documents.view',
            'company_documents.upload',
            'company_documents.update',
            'company_documents.download',
            'company_documents.delete',
        ];

        foreach ($permissionNames as $permissionName) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $permissionName,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $permissionIds = DB::table('permissions')
            ->where('guard_name', 'web')
            ->whereIn('name', $permissionNames)
            ->pluck('id');
        $rolesTable = config('permission.table_names.roles');
        $roleHasPermissions = config('permission.table_names.role_has_permissions');
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

        foreach ([
            'Trade License',
            'Tax Registration Certificate',
            'Certificate of Incorporation',
            'Insurance Policy',
        ] as $title) {
            if (! DB::table('document_types')->where('title', $title)->exists()) {
                DB::table('document_types')->insert([
                    'title' => $title,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
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
        // Preserve permissions, role assignments, and document types that may be in use.
    }
};
