<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\User;
use Carbon\Carbon;

test('contracts expire command marks active contracts with past end dates as ended', function () {
    Carbon::setTestNow('2026-07-03');

    $company = createExpireContractsCompany();
    $employee = Employee::factory()->forCompany($company)->create();

    $expired = EmployeeContract::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'status' => 'active',
        'end_date' => '2026-07-02',
    ]);

    $endsToday = EmployeeContract::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'status' => 'active',
        'end_date' => '2026-07-03',
    ]);

    $future = EmployeeContract::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'status' => 'active',
        'end_date' => '2026-07-04',
    ]);

    $noEndDate = EmployeeContract::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'status' => 'active',
        'end_date' => null,
    ]);

    $alreadyEnded = EmployeeContract::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'status' => 'ended',
        'end_date' => '2026-06-01',
    ]);

    $this->artisan('contracts:expire')
        ->assertSuccessful();

    expect($expired->fresh()->status)->toBe('ended')
        ->and($endsToday->fresh()->status)->toBe('active')
        ->and($future->fresh()->status)->toBe('active')
        ->and($noEndDate->fresh()->status)->toBe('active')
        ->and($alreadyEnded->fresh()->status)->toBe('ended');
});

test('contracts expire command can be limited to a single company', function () {
    Carbon::setTestNow('2026-07-03');

    $companyA = createExpireContractsCompany('expire-co-a');
    $companyB = createExpireContractsCompany('expire-co-b');

    $employeeA = Employee::factory()->forCompany($companyA)->create();
    $employeeB = Employee::factory()->forCompany($companyB)->create();

    $contractA = EmployeeContract::factory()->create([
        'company_id' => $companyA->id,
        'employee_id' => $employeeA->id,
        'status' => 'active',
        'end_date' => '2026-07-01',
    ]);

    $contractB = EmployeeContract::factory()->create([
        'company_id' => $companyB->id,
        'employee_id' => $employeeB->id,
        'status' => 'active',
        'end_date' => '2026-07-01',
    ]);

    $this->artisan('contracts:expire', ['--company' => $companyA->id])
        ->assertSuccessful();

    expect($contractA->fresh()->status)->toBe('ended')
        ->and($contractB->fresh()->status)->toBe('active');
});

test('users cannot save employee contracts with draft status', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = createExpireContractsCompany('contract-draft-co');
    $employee = Employee::factory()->forCompany($company)->create();

    grantCompanyPermissions($user, $company, [
        'employees.view',
        'contracts.create',
    ]);

    $this->post(route('organization.employees.contracts.store', $employee), [
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => 'draft',
    ])->assertSessionHasErrors('status');

    $this->assertDatabaseMissing('employee_contracts', [
        'employee_id' => $employee->id,
        'status' => 'draft',
    ]);
});

function createExpireContractsCompany(string $slug = 'expire-contracts-co'): Company
{
    $code = strtoupper(substr(md5($slug), 0, 3));

    $country = Country::query()->create([
        'code' => $code,
        'name' => 'Expire Contracts Land',
        'dial_code' => '+995',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => $code,
        'name' => 'Expire Contracts Currency',
        'symbol' => 'E$',
        'is_active' => true,
    ]);

    return Company::query()->create([
        'name' => 'Expire Contracts Co',
        'slug' => $slug,
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
}
