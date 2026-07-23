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
    expect(Permission::query()->where('name', 'companies.update')->exists())->toBeTrue();
    expect(Permission::query()->where('name', 'company_documents.view')->exists())->toBeTrue();
    expect(Permission::query()->where('name', 'users.export')->exists())->toBeTrue();
    expect(Permission::query()->where('name', 'reports.crew_movement_history.view')->exists())->toBeTrue();
    expect(Permission::query()->where('name', 'reports.crew_movement_history.export')->exists())->toBeTrue();

    expect(Permission::query()->where('name', 'company.settings.view')->exists())->toBeFalse();
    expect(Permission::query()->where('name', 'company.settings.update')->exists())->toBeFalse();
    expect(Permission::query()->where('name', 'company.document-settings.view')->exists())->toBeFalse();
    expect(Permission::query()->where('name', 'company.document-settings.update')->exists())->toBeFalse();
});
