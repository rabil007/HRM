<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hikvision_person_groups', function (Blueprint $table) {
            $table->id();
            $table->string('group_id')->unique();
            $table->string('name');
            $table->string('parent_id')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('hikvision_persons', function (Blueprint $table) {
            $table->id();
            $table->string('person_id')->unique();
            $table->string('group_id')->nullable()->index();
            $table->string('person_code')->nullable()->index();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('full_name')->nullable()->index();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('photo_url')->nullable();
            $table->boolean('has_fingerprint')->default(false);
            $table->boolean('has_pin')->default(false);
            $table->json('raw_payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        if (! Schema::hasColumn('hikvision_settings', 'persons_last_synced_at')) {
            Schema::table('hikvision_settings', function (Blueprint $table) {
                $table->timestamp('persons_last_synced_at')->nullable()->after('mq_subscribed_at');
            });
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissionNames = [
            'hikvision.persons.view',
            'hikvision.persons.sync',
        ];

        $permissionIds = [];

        foreach ($permissionNames as $name) {
            $permission = Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);

            $permissionIds[] = $permission->id;
        }

        $rolesTable = config('permission.table_names.roles');
        $roleHasPermissions = config('permission.table_names.role_has_permissions');
        $pivotPermission = config('permission.column_names.permission_pivot_key') ?? 'permission_id';
        $pivotRole = config('permission.column_names.role_pivot_key') ?? 'role_id';

        $ownerRoleIds = DB::table($rolesTable)
            ->where('name', 'Owner')
            ->where('guard_name', 'web')
            ->pluck('id');

        foreach ($ownerRoleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table($roleHasPermissions)->insertOrIgnore([
                    $pivotPermission => $permissionId,
                    $pivotRole => $roleId,
                ]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('hikvision_persons');
        Schema::dropIfExists('hikvision_person_groups');

        if (Schema::hasColumn('hikvision_settings', 'persons_last_synced_at')) {
            Schema::table('hikvision_settings', function (Blueprint $table) {
                $table->dropColumn('persons_last_synced_at');
            });
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()
            ->whereIn('name', [
                'hikvision.persons.view',
                'hikvision.persons.sync',
            ])
            ->where('guard_name', 'web')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
