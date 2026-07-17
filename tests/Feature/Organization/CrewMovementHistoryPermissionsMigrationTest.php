<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

test('report permissions are granted to existing assignment viewer roles', function () {
    $migration = require database_path('migrations/2026_07_17_120041_add_crew_movement_history_report_permissions.php');
    expect($migration)->toBeInstanceOf(Migration::class);
    $migration->down();

    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, ['crew_operations.assignments.view']);

    $migration->up();
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $role = Role::query()
        ->where('company_id', $company->id)
        ->where('name', 'test-role')
        ->firstOrFail();

    expect($role->fresh()->hasPermissionTo('reports.crew_movement_history.view'))->toBeTrue()
        ->and($role->fresh()->hasPermissionTo('reports.crew_movement_history.export'))->toBeTrue();
});
