<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('hikvision_users');

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::query()
            ->whereIn('name', [
                'hikvision.users.view',
                'hikvision.users.sync',
            ])
            ->where('guard_name', 'web')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! Schema::hasTable('hikvision_users')) {
            Schema::create('hikvision_users', function (Blueprint $table) {
                $table->id();
                $table->string('hikvision_id')->unique();
                $table->string('name');
                $table->json('raw_payload')->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->timestamps();
            });
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['hikvision.users.view', 'hikvision.users.sync'] as $name) {
            Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
