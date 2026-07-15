<?php

use Spatie\Permission\Models\Permission;

test('legacy employee sea service manage permission is not registered', function () {
    expect(
        Permission::query()
            ->where('guard_name', 'web')
            ->where('name', 'employees.sea_service.manage')
            ->exists(),
    )->toBeFalse();
});

test('sea services module exposes granular permissions only', function () {
    $modulePermissions = Permission::query()
        ->where('guard_name', 'web')
        ->where('name', 'like', 'sea_services.%')
        ->orderBy('name')
        ->pluck('name')
        ->all();

    expect($modulePermissions)->toBe([
        'sea_services.create',
        'sea_services.delete',
        'sea_services.import',
        'sea_services.update',
        'sea_services.view',
    ]);
});
