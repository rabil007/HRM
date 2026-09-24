<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

test('opening update permission backfill grants Owner but not ordinary roles', function () {
    $createMigration = require database_path('migrations/2026_09_24_130620_add_leave_balance_opening_update_permission.php');
    $backfillMigration = require database_path('migrations/2026_09_24_132708_backfill_leave_balance_opening_update_permission_to_owner_roles.php');
    expect($createMigration)->toBeInstanceOf(Migration::class)
        ->and($backfillMigration)->toBeInstanceOf(Migration::class);

    $backfillMigration->down();
    $createMigration->down();

    ['user' => $user, 'company' => $company] = authorizeLeaveReport();
    grantCompanyPermissions($user, $company, ['reports.leave_balance.view', 'attendance.leave-requests.view']);

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

    $owner = User::factory()->create(['status' => 'active']);
    DB::table('company_user')->insert([
        'company_id' => $company->id,
        'user_id' => $owner->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $ownerRole = Role::query()->create([
        'company_id' => $company->id,
        'name' => 'Owner',
        'guard_name' => 'web',
        'employee_visibility_scope' => Role::SCOPE_ALL,
    ]);
    $owner->syncRoles([$ownerRole]);

    $ordinaryRole = Role::query()
        ->where('company_id', $company->id)
        ->where('name', 'test-role')
        ->firstOrFail();

    $createMigration->up();
    $backfillMigration->up();

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

    expect(Permission::query()->where('name', 'reports.leave_balance.update_opening')->exists())->toBeTrue()
        ->and($ownerRole->fresh()->hasPermissionTo('reports.leave_balance.update_opening'))->toBeTrue()
        ->and($ordinaryRole->fresh()->hasPermissionTo('reports.leave_balance.update_opening'))->toBeFalse();
});
