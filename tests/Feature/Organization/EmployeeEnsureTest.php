<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('ensure employee creates draft employee with name', function () {
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'EN',
        'name' => 'Ensure Land',
        'dial_code' => '+1',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'ENC',
        'name' => 'Ensure Currency',
        'symbol' => '$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Ensure Co',
        'slug' => 'ensure-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'UTC',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.create']);

    $response = $this->actingAs($user)->postJson('/organization/employees/ensure', [
        'name' => 'Captain Ahmed',
    ]);

    $response->assertOk()
        ->assertJsonPath('employee.name', 'Captain Ahmed');

    $employee = Employee::query()->first();

    expect($employee)->not->toBeNull()
        ->and($employee->name)->toBe('Captain Ahmed')
        ->and($employee->employee_no)->toStartWith('DRAFT-');
});

test('ensure employee requires name', function () {
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'EN2',
        'name' => 'Ensure Land 2',
        'dial_code' => '+1',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'EN2',
        'name' => 'Ensure Currency 2',
        'symbol' => '$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Ensure Co 2',
        'slug' => 'ensure-co-2',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'UTC',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.create']);

    $this->actingAs($user)
        ->postJson('/organization/employees/ensure', ['name' => ''])
        ->assertUnprocessable();
});
