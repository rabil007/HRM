<?php

use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;

test('permissions seeder creates expected permissions and is idempotent', function () {
    expect(Permission::query()->count())->toBeGreaterThanOrEqual(0);

    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\PermissionsSeeder']);
    $countAfterFirst = Permission::query()->where('guard_name', 'web')->count();

    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\PermissionsSeeder']);
    $countAfterSecond = Permission::query()->where('guard_name', 'web')->count();

    expect($countAfterSecond)->toBe($countAfterFirst);

    expect(Permission::query()->where('name', 'companies.view')->exists())->toBeTrue();
    expect(Permission::query()->where('name', 'users.export')->exists())->toBeTrue();
});
