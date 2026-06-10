<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private array $renames = [
        'crew_deployments.view' => 'crew_operations.deployments.view',
        'crew_deployments.manage' => 'crew_operations.deployments.manage',
    ];

    public function up(): void
    {
        foreach ($this->renames as $from => $to) {
            DB::table('permissions')
                ->where('name', $from)
                ->where('guard_name', 'web')
                ->update(['name' => $to]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach ($this->renames as $from => $to) {
            DB::table('permissions')
                ->where('name', $to)
                ->where('guard_name', 'web')
                ->update(['name' => $from]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
