<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

test('transfer ownership permission migration grants existing roles.update holders one time', function () {
    $migration = require database_path('migrations/2026_10_08_100001_add_recruitment_requirement_transfer_ownership_permission.php');
    expect($migration)->toBeInstanceOf(Migration::class);
    $migration->down();

    $user = User::factory()->create();
    $country = Country::query()->create([
        'code' => 'TOT',
        'name' => 'Transfer Ownership Migration Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->firstOrCreate(
        ['code' => 'AED'],
        [
            'name' => 'Dirham',
            'symbol' => 'د.إ',
            'is_active' => true,
        ],
    );
    $company = Company::query()->create([
        'name' => 'Transfer Ownership Migration Co',
        'slug' => 'transfer-ownership-migration-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['roles.update']);

    $migration->up();
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $role = Role::query()
        ->where('company_id', $company->id)
        ->where('name', 'test-role')
        ->firstOrFail();

    expect($role->fresh()->hasPermissionTo('roles.update'))->toBeTrue()
        ->and($role->fresh()->hasPermissionTo('recruitment.requirements.transfer_ownership'))->toBeTrue();
});
