<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

test('leave report permissions are granted to existing leave viewer roles', function () {
    $migration = require database_path('migrations/2026_09_18_120000_add_leave_report_permissions.php');
    expect($migration)->toBeInstanceOf(Migration::class);
    $migration->down();

    ['user' => $user, 'company' => $company] = authorizeLeaveReport();
    grantCompanyPermissions($user, $company, ['attendance.leave-requests.view']);

    $migration->up();
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $role = Role::query()
        ->where('company_id', $company->id)
        ->where('name', 'test-role')
        ->firstOrFail();

    expect($role->fresh()->hasPermissionTo('reports.leave.view'))->toBeTrue()
        ->and($role->fresh()->hasPermissionTo('reports.leave.export'))->toBeTrue();
});
