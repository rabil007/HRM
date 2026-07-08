<?php

use Spatie\Permission\Models\Permission;

test('legacy payroll adjustment permissions are not registered', function () {
    $legacyNames = [
        'payroll.adjustments.view',
        'payroll.adjustments.create',
        'payroll.adjustments.update',
        'payroll.adjustments.delete',
        'payroll.adjustments.approve',
    ];

    expect(
        Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $legacyNames)
            ->pluck('name')
            ->all(),
    )->toBe([]);
});
