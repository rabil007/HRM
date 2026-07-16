<?php

use Spatie\Permission\Models\Permission;

test('module overview view permissions are registered', function () {
    foreach ([
        'attendance.overview.view',
        'payroll.overview.view',
        'crew_operations.overview.view',
    ] as $name) {
        expect(
            Permission::query()
                ->where('guard_name', 'web')
                ->where('name', $name)
                ->exists(),
        )->toBeTrue();
    }
});
