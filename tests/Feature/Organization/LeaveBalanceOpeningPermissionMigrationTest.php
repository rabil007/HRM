<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

test('leave balance opening permission is created without copying onto existing roles', function () {
    $migration = require database_path('migrations/2026_09_24_130620_add_leave_balance_opening_update_permission.php');
    expect($migration)->toBeInstanceOf(Migration::class);
    $migration->down();

    ['user' => $user, 'company' => $company] = authorizeLeaveReport();
    grantCompanyPermissions($user, $company, ['reports.leave_balance.view', 'attendance.leave-requests.view']);

    $migration->up();
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $role = Role::query()
        ->where('company_id', $company->id)
        ->where('name', 'test-role')
        ->firstOrFail();

    expect($role->fresh()->hasPermissionTo('reports.leave_balance.update_opening'))->toBeFalse();
});
