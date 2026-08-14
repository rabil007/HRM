<?php

use App\Models\DocumentType;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeDocument;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('employee directory defaults to active employees only', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'activeEmployee' => $activeEmployee] = makeActiveOnlyScopeFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get(route('organization.employees'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employees')
            ->has('employees', 1)
            ->where('employees.0.id', $activeEmployee->id)
            ->where('employees.0.name', 'Active Employee'));
});

test('employee directory excludes inactive employees by default', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'activeEmployee' => $activeEmployee] = makeActiveOnlyScopeFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get(route('organization.employees'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 1)
            ->where('employees.0.id', $activeEmployee->id));
});

test('employee directory can still filter by non-active status', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'terminatedEmployee' => $terminatedEmployee] = makeActiveOnlyScopeFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get(route('organization.employees', ['status' => 'terminated']))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employees')
            ->has('employees', 1)
            ->where('employees.0.id', $terminatedEmployee->id));
});

test('contracts no contract list excludes terminated employees', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'activeEmployee' => $activeEmployee] = makeActiveOnlyScopeFixtures();

    grantCompanyPermissions($user, $company, ['contracts.view']);

    $this->get(route('organization.contracts.no-contract', ['payroll_category' => 'office']))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/contracts/no-contract')
            ->has('employees', 1)
            ->where('employees.0.id', $activeEmployee->id));
});

test('bank accounts summary excludes terminated employees from no account count', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeActiveOnlyScopeFixtures();

    grantCompanyPermissions($user, $company, ['bank_accounts.view']);

    $this->get(route('organization.bank-accounts'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/bank-accounts/index')
            ->where('summary.no_account_employees', 1));
});

test('bank account operational totals exclude terminated employee accounts', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'activeEmployee' => $activeEmployee, 'terminatedEmployee' => $terminatedEmployee] = makeActiveOnlyScopeFixtures();

    grantCompanyPermissions($user, $company, ['bank_accounts.view']);

    EmployeeBankAccount::query()->create([
        'company_id' => $company->id,
        'employee_id' => $activeEmployee->id,
        'account_name' => 'Active Holder',
        'iban' => 'AE070331234567890123456',
        'is_primary' => true,
    ]);

    EmployeeBankAccount::query()->create([
        'company_id' => $company->id,
        'employee_id' => $terminatedEmployee->id,
        'account_name' => 'Terminated Holder',
        'iban' => 'AE070331234567890123457',
        'is_primary' => true,
    ]);

    $this->get(route('organization.bank-accounts'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.total_bank_accounts', 1)
            ->where('summary.primary_accounts', 1));
});

test('document operational expiry summary excludes terminated employees', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'activeEmployee' => $activeEmployee, 'terminatedEmployee' => $terminatedEmployee] = makeActiveOnlyScopeFixtures();

    grantCompanyPermissions($user, $company, ['documents.view']);

    $type = DocumentType::query()->create([
        'title' => 'Passport AO',
        'is_active' => true,
    ]);

    foreach ([$activeEmployee, $terminatedEmployee] as $employee) {
        EmployeeDocument::query()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'document_type_id' => $type->id,
            'type' => 'other',
            'document_type' => (string) $type->id,
            'file_path' => 'employee-documents/test/'.$employee->id.'.pdf',
            'expiry_date' => now()->subDay()->toDateString(),
            'status' => 'expired',
        ]);
    }

    $this->get(route('organization.documents'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.total_documents', 1)
            ->where('summary.expired', 1)
            ->has('employees', 1)
            ->where('employees.0.employee_id', $activeEmployee->id));
});

test('terminated employee profile and document folder remain reachable', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'terminatedEmployee' => $terminatedEmployee] = makeActiveOnlyScopeFixtures();

    grantCompanyPermissions($user, $company, ['employees.view', 'documents.view']);

    $type = DocumentType::query()->create([
        'title' => 'Passport History AO',
        'is_active' => true,
    ]);

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $terminatedEmployee->id,
        'document_type_id' => $type->id,
        'type' => 'other',
        'document_type' => (string) $type->id,
        'file_path' => 'employee-documents/test/history-'.$terminatedEmployee->id.'.pdf',
        'expiry_date' => now()->subDay()->toDateString(),
        'status' => 'expired',
    ]);

    $this->get(route('organization.employees.show', $terminatedEmployee))
        ->assertSuccessful();

    $this->get(route('organization.documents.employee', $terminatedEmployee))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/employee')
            ->has('documents', 1)
            ->where('summary.total_documents', 1)
            ->where('employee.id', $terminatedEmployee->id));
});
