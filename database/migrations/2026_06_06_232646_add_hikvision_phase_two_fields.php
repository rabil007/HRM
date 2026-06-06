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
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('hikvision_person_id')
                ->nullable()
                ->after('user_id')
                ->constrained('hikvision_persons')
                ->nullOnDelete();

            $table->unique('hikvision_person_id');
        });

        Schema::table('hikvision_access_events', function (Blueprint $table) {
            $table->string('person_hikvision_id')->nullable()->after('person_name')->index();
            $table->json('snap_urls')->nullable()->after('raw_payload');
        });

        Schema::table('hikvision_settings', function (Blueprint $table) {
            $table->string('webhook_verify_token')->nullable()->after('events_fetch_started_at');
            $table->boolean('webhook_enabled')->default(false)->after('webhook_verify_token');
            $table->string('webhook_callback_url')->nullable()->after('webhook_enabled');
            $table->timestamp('webhook_registered_at')->nullable()->after('webhook_callback_url');
            $table->timestamp('webhook_last_event_at')->nullable()->after('webhook_registered_at');
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissionNames = [
            'hikvision.persons.create',
            'hikvision.persons.update',
            'hikvision.persons.delete',
            'hikvision.persons.link',
            'hikvision.webhook.manage',
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
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hikvision_person_id');
        });

        Schema::table('hikvision_access_events', function (Blueprint $table) {
            $table->dropColumn(['person_hikvision_id', 'snap_urls']);
        });

        Schema::table('hikvision_settings', function (Blueprint $table) {
            $table->dropColumn([
                'webhook_verify_token',
                'webhook_enabled',
                'webhook_callback_url',
                'webhook_registered_at',
                'webhook_last_event_at',
            ]);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()
            ->whereIn('name', [
                'hikvision.persons.create',
                'hikvision.persons.update',
                'hikvision.persons.delete',
                'hikvision.persons.link',
                'hikvision.webhook.manage',
            ])
            ->where('guard_name', 'web')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
