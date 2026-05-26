<?php

use App\Models\Bank;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\User;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateFieldRegistry;

test('bank account store rejects hidden iban field from template', function () {
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'TV',
        'name' => 'Template Validation Land',
        'dial_code' => '+1',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TVL',
        'name' => 'Template Validation Currency',
        'symbol' => '$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Template Validation Co',
        'slug' => 'template-validation-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'UTC',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $configuration = EmployeeProfileTemplateFieldRegistry::defaultConfiguration();
    $configuration['fields']['employee_bank_accounts']['iban']['visible'] = false;

    $template = createEmployeeProfileTemplate($company, 'No IBAN', $configuration);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'EMP-TPL-1',
            'name' => 'Template Employee',
            'status' => 'active',
            'employee_profile_template_id' => $template->id,
        ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'unlimited',
        'start_date' => '2026-01-01',
        'end_date' => null,
        'labor_contract_id' => null,
        'status' => 'active',
    ]);

    $bank = Bank::query()->create([
        'name' => 'Validation Bank',
        'is_active' => true,
    ]);

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.bank_accounts.manage']);

    $this->actingAs($user)
        ->post(route('organization.employees.bank-accounts.store', $employee), [
            'bank_id' => $bank->id,
            'iban' => 'AE999HIDDEN',
            'account_name' => 'Holder',
            'is_primary' => true,
        ])
        ->assertSessionHasErrors('iban');
});

test('employee show exposes hidden iban in template fields for bank tab', function () {
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'TV2',
        'name' => 'Template Validation Land 2',
        'dial_code' => '+1',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TV2',
        'name' => 'Template Validation Currency 2',
        'symbol' => '$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Template Validation Co 2',
        'slug' => 'template-validation-co-2',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'UTC',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $configuration = EmployeeProfileTemplateFieldRegistry::defaultConfiguration();
    $configuration['fields']['employee_bank_accounts']['iban']['visible'] = false;

    $template = createEmployeeProfileTemplate($company, 'No IBAN UI', $configuration);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'EMP-TPL-2',
            'name' => 'Template Employee 2',
            'status' => 'active',
            'employee_profile_template_id' => $template->id,
        ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'unlimited',
        'start_date' => '2026-01-01',
        'end_date' => null,
        'labor_contract_id' => null,
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->actingAs($user)
        ->get(route('organization.employees.show', $employee))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('employee_tabs.template_fields.employee_bank_accounts.iban.visible', false));
});
