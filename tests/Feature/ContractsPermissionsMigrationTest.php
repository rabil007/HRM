<?php

use Spatie\Permission\Models\Permission;

test('legacy employee contract permissions are not registered', function () {
    $legacyNames = [
        'employees.contracts.manage',
        'employees.contracts.import',
        'contracts.manage',
    ];

    expect(
        Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $legacyNames)
            ->pluck('name')
            ->all(),
    )->toBe([]);
});

test('contracts module exposes granular permissions only', function () {
    $modulePermissions = Permission::query()
        ->where('guard_name', 'web')
        ->where('name', 'like', 'contracts.%')
        ->orderBy('name')
        ->pluck('name')
        ->all();

    expect($modulePermissions)->toBe([
        'contracts.create',
        'contracts.delete',
        'contracts.import',
        'contracts.salary_revisions.manage',
        'contracts.update',
        'contracts.view',
    ]);
});
