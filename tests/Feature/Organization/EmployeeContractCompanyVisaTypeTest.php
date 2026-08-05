<?php

use App\Enums\PayrollCategory;
use App\Models\Company;
use App\Models\CompanyVisaType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\User;
use App\Support\Employees\Resources\EmployeeContractResource;
use App\Support\Payroll\ResolveCrewContractForWorkDate;
use Illuminate\Support\Facades\Artisan;

/**
 * @return array{user: User, company: Company}
 */
function makeVisaTypeContractFixtures(): array
{
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'CVT'.fake()->unique()->numerify('##'),
        'name' => 'Visa Type Contract Land',
        'dial_code' => '+992',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CVT'.fake()->unique()->numerify('##'),
        'name' => 'Visa Type Contract Currency',
        'symbol' => 'V$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Visa Type Contract Co',
        'slug' => 'visa-type-contract-co-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    return ['user' => $user, 'company' => $company];
}

test('contract can store company_visa_type_id', function () {
    ['company' => $company] = makeVisaTypeContractFixtures();

    $visaType = CompanyVisaType::query()->create(['name' => 'Store Visa Co '.uniqid(), 'is_active' => true]);
    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create(['status' => 'active']);

    $contract = EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'payroll_category' => PayrollCategory::Office->value,
        'start_date' => '2026-01-01',
        'status' => 'active',
        'company_visa_type_id' => $visaType->id,
    ]);

    expect($contract->fresh()->company_visa_type_id)->toBe($visaType->id)
        ->and($contract->fresh()->companyVisaType->name)->toBe($visaType->name);
});

test('contract resource returns company visa type', function () {
    ['company' => $company] = makeVisaTypeContractFixtures();

    $visaType = CompanyVisaType::query()->create(['name' => 'Resource Visa Co '.uniqid(), 'is_active' => true]);
    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create(['status' => 'active']);

    $contract = EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'payroll_category' => PayrollCategory::Office->value,
        'start_date' => '2026-01-01',
        'status' => 'active',
        'company_visa_type_id' => $visaType->id,
    ]);

    $contract->load('companyVisaType', 'salaryRevisions.lines');

    $resource = EmployeeContractResource::toArray($contract);

    expect($resource['company_visa_type_id'])->toBe($visaType->id)
        ->and($resource['company_visa_type'])->toBe(['id' => $visaType->id, 'name' => $visaType->name]);
});

test('contract create defaults company visa type from employee current value', function () {
    ['user' => $user, 'company' => $company] = makeVisaTypeContractFixtures();
    $this->actingAs($user);

    $visaType = CompanyVisaType::query()->create(['name' => 'Default Visa Co '.uniqid(), 'is_active' => true]);
    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create([
        'status' => 'active',
        'company_visa_type_id' => $visaType->id,
    ]);

    grantCompanyPermissions($user, $company, ['contracts.create']);

    $this->post(route('organization.employees.contracts.store', $employee), [
        'start_date' => '2026-01-01',
        'status' => 'active',
        'basic_salary' => 5000,
    ])->assertRedirect();

    $contract = EmployeeContract::query()
        ->where('employee_id', $employee->id)
        ->where('status', 'active')
        ->first();

    expect($contract)->not->toBeNull()
        ->and($contract->company_visa_type_id)->toBe($visaType->id);
});

test('explicit contract company visa type overrides the employee default', function () {
    ['user' => $user, 'company' => $company] = makeVisaTypeContractFixtures();
    $this->actingAs($user);

    $employeeDefaultVisaType = CompanyVisaType::query()->create(['name' => 'Employee Default Visa Co '.uniqid(), 'is_active' => true]);
    $explicitVisaType = CompanyVisaType::query()->create(['name' => 'Explicit Visa Co '.uniqid(), 'is_active' => true]);

    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create([
        'status' => 'active',
        'company_visa_type_id' => $employeeDefaultVisaType->id,
    ]);

    grantCompanyPermissions($user, $company, ['contracts.create']);

    $this->post(route('organization.employees.contracts.store', $employee), [
        'start_date' => '2026-01-01',
        'status' => 'active',
        'basic_salary' => 5000,
        'company_visa_type_id' => $explicitVisaType->id,
    ])->assertRedirect();

    $contract = EmployeeContract::query()
        ->where('employee_id', $employee->id)
        ->where('status', 'active')
        ->first();

    expect($contract)->not->toBeNull()
        ->and($contract->company_visa_type_id)->toBe($explicitVisaType->id);
});

test('creating the latest active contract updates employee current company visa type', function () {
    ['user' => $user, 'company' => $company] = makeVisaTypeContractFixtures();
    $this->actingAs($user);

    $oldVisaType = CompanyVisaType::query()->create(['name' => 'Sync Old Visa Co '.uniqid(), 'is_active' => true]);
    $newVisaType = CompanyVisaType::query()->create(['name' => 'Sync New Visa Co '.uniqid(), 'is_active' => true]);

    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create([
        'status' => 'active',
        'company_visa_type_id' => $oldVisaType->id,
    ]);

    grantCompanyPermissions($user, $company, ['contracts.create']);

    $this->post(route('organization.employees.contracts.store', $employee), [
        'start_date' => '2026-01-01',
        'status' => 'active',
        'basic_salary' => 5000,
        'company_visa_type_id' => $newVisaType->id,
    ])->assertRedirect();

    expect($employee->fresh()->company_visa_type_id)->toBe($newVisaType->id);
});

test('editing an ended historical contract does not update employee current value', function () {
    ['user' => $user, 'company' => $company] = makeVisaTypeContractFixtures();
    $this->actingAs($user);

    $employeeVisaType = CompanyVisaType::query()->create(['name' => 'Historical Employee Visa Co '.uniqid(), 'is_active' => true]);
    $historicalVisaType = CompanyVisaType::query()->create(['name' => 'Historical Contract Visa Co '.uniqid(), 'is_active' => true]);

    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create([
        'status' => 'active',
        'company_visa_type_id' => $employeeVisaType->id,
    ]);

    $endedContract = EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'payroll_category' => PayrollCategory::Office->value,
        'start_date' => '2024-01-01',
        'end_date' => '2024-12-31',
        'status' => 'ended',
        'company_visa_type_id' => $historicalVisaType->id,
    ]);

    grantCompanyPermissions($user, $company, ['contracts.update']);

    $this->put(route('organization.employees.contracts.update', [$employee, $endedContract]), [
        'start_date' => '2024-01-01',
        'end_date' => '2024-12-31',
        'status' => 'ended',
        'basic_salary' => 4000,
        'company_visa_type_id' => $historicalVisaType->id,
    ])->assertRedirect();

    expect($employee->fresh()->company_visa_type_id)->toBe($employeeVisaType->id)
        ->and($endedContract->fresh()->company_visa_type_id)->toBe($historicalVisaType->id);
});

test('backfill copies employee current value onto the latest active contract when missing', function () {
    ['company' => $company] = makeVisaTypeContractFixtures();

    $visaType = CompanyVisaType::query()->create(['name' => 'Backfill Visa Co '.uniqid(), 'is_active' => true]);
    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create([
        'status' => 'active',
        'company_visa_type_id' => $visaType->id,
    ]);

    $activeContract = EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'payroll_category' => PayrollCategory::Office->value,
        'start_date' => '2026-01-01',
        'status' => 'active',
        'company_visa_type_id' => null,
    ]);

    Artisan::call('contracts:backfill-company-visa-types');

    expect($activeContract->fresh()->company_visa_type_id)->toBe($visaType->id);
});

test('backfill does not touch ended historical contracts', function () {
    ['company' => $company] = makeVisaTypeContractFixtures();

    $visaType = CompanyVisaType::query()->create(['name' => 'Backfill Historical Visa Co '.uniqid(), 'is_active' => true]);
    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create([
        'status' => 'active',
        'company_visa_type_id' => $visaType->id,
    ]);

    $endedContract = EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'payroll_category' => PayrollCategory::Office->value,
        'start_date' => '2024-01-01',
        'end_date' => '2024-12-31',
        'status' => 'ended',
        'company_visa_type_id' => null,
    ]);

    Artisan::call('contracts:backfill-company-visa-types');

    expect($endedContract->fresh()->company_visa_type_id)->toBeNull();
});

test('backfill does not overwrite an existing non-null contract value', function () {
    ['company' => $company] = makeVisaTypeContractFixtures();

    $employeeVisaType = CompanyVisaType::query()->create(['name' => 'Backfill Employee Visa Co '.uniqid(), 'is_active' => true]);
    $contractVisaType = CompanyVisaType::query()->create(['name' => 'Backfill Contract Visa Co '.uniqid(), 'is_active' => true]);

    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create([
        'status' => 'active',
        'company_visa_type_id' => $employeeVisaType->id,
    ]);

    $activeContract = EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'payroll_category' => PayrollCategory::Office->value,
        'start_date' => '2026-01-01',
        'status' => 'active',
        'company_visa_type_id' => $contractVisaType->id,
    ]);

    Artisan::call('contracts:backfill-company-visa-types');

    expect($activeContract->fresh()->company_visa_type_id)->toBe($contractVisaType->id);
});

test('backfill is idempotent across repeated runs', function () {
    ['company' => $company] = makeVisaTypeContractFixtures();

    $visaType = CompanyVisaType::query()->create(['name' => 'Idempotent Visa Co '.uniqid(), 'is_active' => true]);
    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create([
        'status' => 'active',
        'company_visa_type_id' => $visaType->id,
    ]);

    $activeContract = EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'payroll_category' => PayrollCategory::Office->value,
        'start_date' => '2026-01-01',
        'status' => 'active',
        'company_visa_type_id' => null,
    ]);

    Artisan::call('contracts:backfill-company-visa-types');
    Artisan::call('contracts:backfill-company-visa-types');

    expect($activeContract->fresh()->company_visa_type_id)->toBe($visaType->id);
});

test('ended contracts remain valid for historical work dates regardless of visa type', function () {
    ['company' => $company] = makeVisaTypeContractFixtures();

    $companyA = CompanyVisaType::query()->create(['name' => 'Resolver Company A '.uniqid(), 'is_active' => true]);
    $companyB = CompanyVisaType::query()->create(['name' => 'Resolver Company B '.uniqid(), 'is_active' => true]);

    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create(['status' => 'active']);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'payroll_category' => PayrollCategory::Crew->value,
        'salary_structure' => 'daily',
        'start_date' => '2026-02-03',
        'end_date' => '2026-07-06',
        'status' => 'ended',
        'company_visa_type_id' => $companyA->id,
        'basic_salary' => 200,
    ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'payroll_category' => PayrollCategory::Crew->value,
        'salary_structure' => 'daily',
        'start_date' => '2026-07-07',
        'status' => 'active',
        'company_visa_type_id' => $companyB->id,
        'basic_salary' => 220,
    ]);

    $resolver = app(ResolveCrewContractForWorkDate::class);

    $beforeResult = $resolver->resolve($company->id, $employee->id, '2026-07-06');
    $afterResult = $resolver->resolve($company->id, $employee->id, '2026-07-07');

    expect($beforeResult['contract']->company_visa_type_id)->toBe($companyA->id)
        ->and($afterResult['contract']->company_visa_type_id)->toBe($companyB->id);
});

test('overlapping contracts with different company visa types are still rejected by the resolver', function () {
    ['company' => $company] = makeVisaTypeContractFixtures();

    $companyA = CompanyVisaType::query()->create(['name' => 'Overlap Company A '.uniqid(), 'is_active' => true]);
    $companyB = CompanyVisaType::query()->create(['name' => 'Overlap Company B '.uniqid(), 'is_active' => true]);

    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create(['status' => 'active']);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'payroll_category' => PayrollCategory::Crew->value,
        'salary_structure' => 'daily',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => 'ended',
        'company_visa_type_id' => $companyA->id,
        'basic_salary' => 200,
    ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'payroll_category' => PayrollCategory::Crew->value,
        'salary_structure' => 'daily',
        'start_date' => '2026-06-01',
        'status' => 'active',
        'company_visa_type_id' => $companyB->id,
        'basic_salary' => 220,
    ]);

    $resolver = app(ResolveCrewContractForWorkDate::class);
    $result = $resolver->resolve($company->id, $employee->id, '2026-07-01');

    expect($result['contract'])->toBeNull()
        ->and($result['issue']['code'])->toBe('overlapping_historical_contracts');
});

test('soft-deleted contracts remain excluded from resolution regardless of visa type', function () {
    ['company' => $company] = makeVisaTypeContractFixtures();

    $visaType = CompanyVisaType::query()->create(['name' => 'Soft Delete Visa Co '.uniqid(), 'is_active' => true]);
    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create(['status' => 'active']);

    $deletedContract = EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'payroll_category' => PayrollCategory::Crew->value,
        'salary_structure' => 'daily',
        'start_date' => '2026-01-01',
        'status' => 'active',
        'company_visa_type_id' => $visaType->id,
        'basic_salary' => 150,
    ]);
    $deletedContract->delete();

    $resolver = app(ResolveCrewContractForWorkDate::class);
    $result = $resolver->resolve($company->id, $employee->id, '2026-06-15');

    expect($result['contract'])->toBeNull()
        ->and($result['issue']['code'])->toBe('missing_historical_contract');
});

test('employee current visa value and contract historical visa values remain independent', function () {
    ['company' => $company] = makeVisaTypeContractFixtures();

    $originalVisaType = CompanyVisaType::query()->create(['name' => 'Independence Original Visa Co '.uniqid(), 'is_active' => true]);
    $laterVisaType = CompanyVisaType::query()->create(['name' => 'Independence Later Visa Co '.uniqid(), 'is_active' => true]);

    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create([
        'status' => 'active',
        'company_visa_type_id' => $originalVisaType->id,
    ]);

    $historicalContract = EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'payroll_category' => PayrollCategory::Office->value,
        'start_date' => '2024-01-01',
        'end_date' => '2024-12-31',
        'status' => 'ended',
        'company_visa_type_id' => $originalVisaType->id,
    ]);

    $employee->update(['company_visa_type_id' => $laterVisaType->id]);

    expect($historicalContract->fresh()->company_visa_type_id)->toBe($originalVisaType->id)
        ->and($employee->fresh()->company_visa_type_id)->toBe($laterVisaType->id);
});
