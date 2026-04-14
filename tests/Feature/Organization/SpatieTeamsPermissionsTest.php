<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

test('spatie roles and permissions are scoped by current_company_id (teams)', function () {
    $user = User::factory()->create([
        'company_id' => null,
    ]);

    $country = Country::query()->create([
        'code' => 'TST',
        'name' => 'Testland',
        'dial_code' => '+999',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TST',
        'name' => 'Test Currency',
        'symbol' => 'T$',
        'is_active' => true,
    ]);

    $companyA = Company::query()->create([
        'name' => 'Company A',
        'slug' => 'company-a',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $companyB = Company::query()->create([
        'name' => 'Company B',
        'slug' => 'company-b',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    DB::table('company_user')->insert([
        [
            'company_id' => $companyA->id,
            'user_id' => $user->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'company_id' => $companyB->id,
            'user_id' => $user->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $permission = Permission::query()->firstOrCreate(['name' => 'companies.view', 'guard_name' => 'web']);

    $roleA = Role::query()->firstOrCreate([
        'company_id' => $companyA->id,
        'name' => 'viewer',
        'guard_name' => 'web',
    ]);
    $roleA->givePermissionTo($permission);

    $roleB = Role::query()->firstOrCreate([
        'company_id' => $companyB->id,
        'name' => 'viewer',
        'guard_name' => 'web',
    ]);

    $this->actingAs($user);

    $this->withSession(['current_company_id' => $companyA->id])->get('/dashboard')->assertOk();
    app(PermissionRegistrar::class)->setPermissionsTeamId($companyA->id);
    $user->syncRoles([$roleA]);
    expect($user->can('companies.view'))->toBeTrue();

    $this->withSession(['current_company_id' => $companyB->id])->get('/dashboard')->assertOk();
    app(PermissionRegistrar::class)->setPermissionsTeamId($companyB->id);
    $user->syncRoles([$roleB]);
    expect($user->can('companies.view'))->toBeFalse();
});
