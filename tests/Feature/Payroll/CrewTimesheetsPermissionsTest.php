<?php

use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;

test('legacy crew timesheet delete permission is not registered', function () {
    expect(
        Permission::query()
            ->where('guard_name', 'web')
            ->where('name', 'payroll.crew_timesheets.delete')
            ->exists(),
    )->toBeFalse();
});

test('crew timesheets module exposes granular permissions only', function () {
    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\PermissionsSeeder']);

    $modulePermissions = Permission::query()
        ->where('guard_name', 'web')
        ->where('name', 'like', 'payroll.crew_timesheets.%')
        ->orderBy('name')
        ->pluck('name')
        ->all();

    expect($modulePermissions)->toBe([
        'payroll.crew_timesheets.create',
        'payroll.crew_timesheets.import',
        'payroll.crew_timesheets.update',
        'payroll.crew_timesheets.view',
    ]);
});
