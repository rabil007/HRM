<?php

use App\Enums\PayrollCategory;
use App\Models\Company;
use App\Models\CompanyVisaType;
use App\Models\ContractSalaryRevision;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\User;
use App\Support\Contracts\Actions\ApplyContractSalaryRevision;
use App\Support\Contracts\Actions\TransferEmployeeVisaCompanyContract;
use App\Support\Contracts\Actions\UpsertEmployeeContract;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * @return array{user: User, company: Company}
 */
function makeVisaTransferFixtures(): array
{
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'VXT'.fake()->unique()->numerify('##'),
        'name' => 'Visa Transfer Land',
        'dial_code' => '+993',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'VXT'.fake()->unique()->numerify('##'),
        'name' => 'Visa Transfer Currency',
        'symbol' => 'T$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Visa Transfer Co',
        'slug' => 'visa-transfer-co-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    return ['user' => $user, 'company' => $company];
}

/**
 * @return array{employee: Employee, contract: EmployeeContract, companyA: CompanyVisaType, companyB: CompanyVisaType}
 */
function makeAhmadStyleTransferSetup(Company $company): array
{
    $companyA = CompanyVisaType::query()->create(['name' => 'Ahmad Company A '.uniqid(), 'is_active' => true]);
    $companyB = CompanyVisaType::query()->create(['name' => 'Ahmad Company B '.uniqid(), 'is_active' => true]);

    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create([
        'name' => 'AHMAD BARGHOUD',
        'status' => 'active',
        'company_visa_type_id' => $companyA->id,
    ]);

    $contract = EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'payroll_category' => PayrollCategory::Crew->value,
        'salary_structure' => 'daily',
        'start_date' => '2026-02-03',
        'status' => 'active',
        'company_visa_type_id' => $companyA->id,
        'basic_salary' => 200,
    ]);

    app(ApplyContractSalaryRevision::class)->handle(
        $contract->fresh(),
        ['basic_salary' => 200],
        '2026-02-03',
        'Initial contract salary',
    );

    return compact('employee', 'contract', 'companyA', 'companyB');
}

test('transfer ends the old contract one day before the new contract begins', function () {
    ['company' => $company] = makeVisaTransferFixtures();
    ['employee' => $employee, 'contract' => $oldContract, 'companyB' => $companyB] = makeAhmadStyleTransferSetup($company);

    app(TransferEmployeeVisaCompanyContract::class)->handle(
        companyId: $company->id,
        employee: $employee,
        oldContract: $oldContract,
        newCompanyVisaTypeId: $companyB->id,
        transferDate: '2026-07-07',
        newContractAttributes: ['basic_salary' => 220],
    );

    expect($oldContract->fresh()->end_date->toDateString())->toBe('2026-07-06');
});

test('transfer preserves the old contract without soft-deleting it', function () {
    ['company' => $company] = makeVisaTransferFixtures();
    ['employee' => $employee, 'contract' => $oldContract, 'companyB' => $companyB] = makeAhmadStyleTransferSetup($company);

    app(TransferEmployeeVisaCompanyContract::class)->handle(
        companyId: $company->id,
        employee: $employee,
        oldContract: $oldContract,
        newCompanyVisaTypeId: $companyB->id,
        transferDate: '2026-07-07',
        newContractAttributes: ['basic_salary' => 220],
    );

    $fresh = $oldContract->fresh();
    expect($fresh)->not->toBeNull()
        ->and($fresh->trashed())->toBeFalse()
        ->and($fresh->status)->toBe('ended');
});

test('transfer creates a new active contract with the new company visa type', function () {
    ['company' => $company] = makeVisaTransferFixtures();
    ['employee' => $employee, 'contract' => $oldContract, 'companyB' => $companyB] = makeAhmadStyleTransferSetup($company);

    $newContract = app(TransferEmployeeVisaCompanyContract::class)->handle(
        companyId: $company->id,
        employee: $employee,
        oldContract: $oldContract,
        newCompanyVisaTypeId: $companyB->id,
        transferDate: '2026-07-07',
        newContractAttributes: ['basic_salary' => 220],
    );

    expect($newContract->status)->toBe('active')
        ->and($newContract->company_visa_type_id)->toBe($companyB->id)
        ->and($newContract->start_date->toDateString())->toBe('2026-07-07')
        ->and($newContract->id)->not->toBe($oldContract->id);
});

test('transfer updates the employee current company visa type', function () {
    ['company' => $company] = makeVisaTransferFixtures();
    ['employee' => $employee, 'contract' => $oldContract, 'companyB' => $companyB] = makeAhmadStyleTransferSetup($company);

    app(TransferEmployeeVisaCompanyContract::class)->handle(
        companyId: $company->id,
        employee: $employee,
        oldContract: $oldContract,
        newCompanyVisaTypeId: $companyB->id,
        transferDate: '2026-07-07',
        newContractAttributes: ['basic_salary' => 220],
    );

    expect($employee->fresh()->company_visa_type_id)->toBe($companyB->id);
});

test('transfer creates the correct initial salary revision for the new contract', function () {
    ['company' => $company] = makeVisaTransferFixtures();
    ['employee' => $employee, 'contract' => $oldContract, 'companyB' => $companyB] = makeAhmadStyleTransferSetup($company);

    $newContract = app(TransferEmployeeVisaCompanyContract::class)->handle(
        companyId: $company->id,
        employee: $employee,
        oldContract: $oldContract,
        newCompanyVisaTypeId: $companyB->id,
        transferDate: '2026-07-07',
        newContractAttributes: ['basic_salary' => 220],
    );

    $revision = ContractSalaryRevision::query()->where('contract_id', $newContract->id)->first();

    // Salary revisions are normalized to the start of the effective month;
    // this mirrors existing contract-creation behavior.
    expect($revision)->not->toBeNull()
        ->and($revision->effective_from->toDateString())->toBe('2026-07-01');
});

test('old salary revisions remain attached to the old contract after transfer', function () {
    ['company' => $company] = makeVisaTransferFixtures();
    ['employee' => $employee, 'contract' => $oldContract, 'companyB' => $companyB] = makeAhmadStyleTransferSetup($company);

    $oldRevisionsBefore = ContractSalaryRevision::query()->where('contract_id', $oldContract->id)->count();
    expect($oldRevisionsBefore)->toBeGreaterThan(0);

    app(TransferEmployeeVisaCompanyContract::class)->handle(
        companyId: $company->id,
        employee: $employee,
        oldContract: $oldContract,
        newCompanyVisaTypeId: $companyB->id,
        transferDate: '2026-07-07',
        newContractAttributes: ['basic_salary' => 220],
    );

    expect(ContractSalaryRevision::query()->where('contract_id', $oldContract->id)->count())
        ->toBe($oldRevisionsBefore);
});

test('different company visa types with overlapping dates are still rejected without transfer', function () {
    ['company' => $company] = makeVisaTransferFixtures();
    ['employee' => $employee, 'contract' => $oldContract] = makeAhmadStyleTransferSetup($company);

    $companyC = CompanyVisaType::query()->create(['name' => 'Overlap Reject Visa Co '.uniqid(), 'is_active' => true]);

    // Directly creating a second active contract that starts before the
    // first contract's own start date cannot be safely auto-corrected.
    expect(fn () => app(UpsertEmployeeContract::class)->handle(
        $company->id,
        $employee,
        [
            'start_date' => '2026-01-01',
            'status' => 'active',
            'payroll_category' => PayrollCategory::Crew->value,
            'salary_structure' => 'daily',
            'company_visa_type_id' => $companyC->id,
            'basic_salary' => 100,
        ],
    ))->toThrow(ValidationException::class);

    expect($oldContract->fresh()->status)->toBe('active');
});

test('unauthorized users cannot perform visa company transfers', function () {
    ['user' => $user, 'company' => $company] = makeVisaTransferFixtures();
    $this->actingAs($user);

    ['employee' => $employee, 'contract' => $oldContract, 'companyB' => $companyB] = makeAhmadStyleTransferSetup($company);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->post(route('organization.employees.contracts.transfer-visa-company', [$employee, $oldContract]), [
        'company_visa_type_id' => $companyB->id,
        'transfer_date' => '2026-07-07',
    ])->assertForbidden();

    expect($oldContract->fresh()->status)->toBe('active');
});

test('guests cannot perform visa company transfers', function () {
    ['company' => $company] = makeVisaTransferFixtures();
    ['employee' => $employee, 'contract' => $oldContract, 'companyB' => $companyB] = makeAhmadStyleTransferSetup($company);

    $this->post(route('organization.employees.contracts.transfer-visa-company', [$employee, $oldContract]), [
        'company_visa_type_id' => $companyB->id,
        'transfer_date' => '2026-07-07',
    ])->assertRedirect(route('login'));
});

test('cross-company employee and contract ids are rejected for visa transfers', function () {
    ['user' => $user, 'company' => $company] = makeVisaTransferFixtures();
    $this->actingAs($user);

    ['company' => $otherCompany] = makeVisaTransferFixtures();
    ['employee' => $foreignEmployee, 'contract' => $foreignContract, 'companyB' => $companyB] = makeAhmadStyleTransferSetup($otherCompany);

    // The acting user only belongs to $company, so the session resolves
    // current_company_id to $company, not $otherCompany.
    grantCompanyPermissions($user, $company, ['contracts.create']);

    $this->post(route('organization.employees.contracts.transfer-visa-company', [$foreignEmployee, $foreignContract]), [
        'company_visa_type_id' => $companyB->id,
        'transfer_date' => '2026-07-07',
    ])->assertForbidden();

    expect($foreignContract->fresh()->status)->toBe('active');
});

test('transfer action rejects tenant mismatched employee and contract', function () {
    ['company' => $company] = makeVisaTransferFixtures();
    ['company' => $otherCompany] = makeVisaTransferFixtures();

    ['employee' => $employee] = makeAhmadStyleTransferSetup($company);
    ['contract' => $foreignContract, 'companyB' => $companyB] = makeAhmadStyleTransferSetup($otherCompany);

    expect(fn () => app(TransferEmployeeVisaCompanyContract::class)->handle(
        companyId: $company->id,
        employee: $employee,
        oldContract: $foreignContract,
        newCompanyVisaTypeId: $companyB->id,
        transferDate: '2026-07-07',
        newContractAttributes: [],
    ))->toThrow(ValidationException::class);
});

test('transfer via http route succeeds for authorized users and records activity log', function () {
    ['user' => $user, 'company' => $company] = makeVisaTransferFixtures();
    $this->actingAs($user);

    ['employee' => $employee, 'contract' => $oldContract, 'companyA' => $companyA, 'companyB' => $companyB] = makeAhmadStyleTransferSetup($company);

    grantCompanyPermissions($user, $company, ['contracts.create']);

    $this->post(route('organization.employees.contracts.transfer-visa-company', [$employee, $oldContract]), [
        'company_visa_type_id' => $companyB->id,
        'transfer_date' => '2026-07-07',
        'payroll_category' => PayrollCategory::Crew->value,
        'salary_structure' => 'daily',
        'basic_salary' => 220,
        'reason' => 'Employee moved to a new visa sponsor.',
    ])->assertRedirect();

    expect($oldContract->fresh()->status)->toBe('ended')
        ->and($oldContract->fresh()->end_date->toDateString())->toBe('2026-07-06');

    $newContract = EmployeeContract::query()
        ->where('employee_id', $employee->id)
        ->where('status', 'active')
        ->first();

    expect($newContract)->not->toBeNull()
        ->and($newContract->company_visa_type_id)->toBe($companyB->id)
        ->and($employee->fresh()->company_visa_type_id)->toBe($companyB->id);

    $activity = Activity::query()
        ->where('description', 'Employee visa company transferred')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties['employee_id'])->toBe($employee->id)
        ->and($activity->properties['old_contract_id'])->toBe($oldContract->id)
        ->and($activity->properties['new_contract_id'])->toBe($newContract->id)
        ->and($activity->properties['previous_company_visa_type_id'])->toBe($companyA->id)
        ->and($activity->properties['new_company_visa_type_id'])->toBe($companyB->id)
        ->and($activity->properties['transfer_date'])->toBe('2026-07-07')
        ->and($activity->properties['reason'])->toBe('Employee moved to a new visa sponsor.');
});
