<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $permissions = Permission::query()
            ->whereIn('name', [
                'crew_operations.rank_policies.view',
                'crew_operations.rank_policies.update',
            ])
            ->get();

        foreach ($permissions as $permission) {
            $permission->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Obsolete permissions; do not restore on rollback.
    }
};
