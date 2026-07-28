<?php

use Spatie\Permission\Models\Permission;

test('legacy employee profile tab manage permissions are not registered', function () {
    foreach ([
        'employees.education.manage',
        'employees.work_experience.manage',
        'employees.vaccination.manage',
        'employees.languages.manage',
    ] as $legacy) {
        expect(
            Permission::query()
                ->where('guard_name', 'web')
                ->where('name', $legacy)
                ->exists(),
        )->toBeFalse();
    }
});

test('education module exposes granular permissions only', function () {
    $modulePermissions = Permission::query()
        ->where('guard_name', 'web')
        ->where('name', 'like', 'education.%')
        ->orderBy('name')
        ->pluck('name')
        ->all();

    expect($modulePermissions)->toBe([
        'education.create',
        'education.delete',
        'education.update',
        'education.view',
    ]);
});

test('work experience module exposes granular permissions only', function () {
    $modulePermissions = Permission::query()
        ->where('guard_name', 'web')
        ->where('name', 'like', 'work_experience.%')
        ->orderBy('name')
        ->pluck('name')
        ->all();

    expect($modulePermissions)->toBe([
        'work_experience.create',
        'work_experience.delete',
        'work_experience.import',
        'work_experience.update',
        'work_experience.view',
    ]);
});

test('vaccination module exposes granular permissions only', function () {
    $modulePermissions = Permission::query()
        ->where('guard_name', 'web')
        ->where('name', 'like', 'vaccination.%')
        ->orderBy('name')
        ->pluck('name')
        ->all();

    expect($modulePermissions)->toBe([
        'vaccination.create',
        'vaccination.delete',
        'vaccination.import',
        'vaccination.update',
        'vaccination.view',
    ]);
});

test('languages module exposes granular permissions only', function () {
    $modulePermissions = Permission::query()
        ->where('guard_name', 'web')
        ->where('name', 'like', 'languages.%')
        ->orderBy('name')
        ->pluck('name')
        ->all();

    expect($modulePermissions)->toBe([
        'languages.create',
        'languages.delete',
        'languages.update',
        'languages.view',
    ]);
});
