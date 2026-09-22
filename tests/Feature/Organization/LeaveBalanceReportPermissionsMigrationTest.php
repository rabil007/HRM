<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

test('leave balance report permissions are created without copying them onto existing roles', function () {
    $migration = require database_path('migrations/2026_09_22_091736_add_leave_balance_report_permissions.php');
    expect($migration)->toBeInstanceOf(Migration::class);
    $migration->down();

    ['user' => $user, 'company' => $company] = authorizeLeaveReport();
    grantCompanyPermissions($user, $company, ['reports.leave.view', 'attendance.leave-requests.view']);

    $migration->up();
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $role = Role::query()
        ->where('company_id', $company->id)
        ->where('name', 'test-role')
        ->firstOrFail();

    expect($role->fresh()->hasPermissionTo('reports.leave_balance.view'))->toBeFalse()
        ->and($role->fresh()->hasPermissionTo('reports.leave_balance.export'))->toBeFalse();
});
