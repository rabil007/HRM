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
        Schema::dropIfExists('hikvision_persons');

        if (Schema::hasColumn('hikvision_settings', 'persons_last_synced_at')) {
            Schema::table('hikvision_settings', function (Blueprint $table) {
                $table->dropColumn('persons_last_synced_at');
            });
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissionNames = [
            'hikvision.persons.view',
            'hikvision.persons.sync',
        ];

        $permissionIds = Permission::query()
            ->whereIn('name', $permissionNames)
            ->where('guard_name', 'web')
            ->pluck('id');

        if ($permissionIds->isNotEmpty()) {
            $modelHasPermissions = config('permission.table_names.model_has_permissions');
            $roleHasPermissions = config('permission.table_names.role_has_permissions');
            $pivotPermission = config('permission.column_names.permission_pivot_key') ?? 'permission_id';

            DB::table($modelHasPermissions)
                ->whereIn($pivotPermission, $permissionIds)
                ->delete();

            DB::table($roleHasPermissions)
                ->whereIn($pivotPermission, $permissionIds)
                ->delete();

            Permission::query()
                ->whereIn('id', $permissionIds)
                ->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::create('hikvision_persons', function (Blueprint $table) {
            $table->id();
            $table->string('person_id')->unique();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_expired')->default(false);
            $table->string('photo_url')->nullable();
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

        foreach (['hikvision.persons.view', 'hikvision.persons.sync'] as $name) {
            Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
