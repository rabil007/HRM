<?php

use Spatie\Permission\Models\Permission;

test('legacy employee training manage permission is not registered', function () {
    expect(
        Permission::query()
            ->where('guard_name', 'web')
            ->where('name', 'employees.training.manage')
            ->exists(),
    )->toBeFalse();
});

test('training module exposes granular permissions only', function () {
    $modulePermissions = Permission::query()
        ->where('guard_name', 'web')
        ->where('name', 'like', 'training.%')
        ->orderBy('name')
        ->pluck('name')
        ->all();

    expect($modulePermissions)->toBe([
        'training.create',
        'training.delete',
        'training.import',
        'training.update',
        'training.view',
    ]);
});
