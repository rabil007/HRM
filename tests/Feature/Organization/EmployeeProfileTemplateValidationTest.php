<?php

use App\Models\Bank;
use App\Models\Company;
use App\Models\Country;
use App\Models\Course;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeTraining;
use App\Models\User;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateFieldRegistry;
use Illuminate\Http\UploadedFile;

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

test('training store rejects certificate upload when template hides certificate_path', function () {
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'TRC',
        'name' => 'Training Cert Land',
        'dial_code' => '+1',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TRC',
        'name' => 'Training Cert Currency',
        'symbol' => '$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Training Cert Co',
        'slug' => 'training-cert-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'UTC',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $configuration = EmployeeProfileTemplateFieldRegistry::defaultConfiguration();
    $configuration['fields']['employee_trainings']['certificate_path']['visible'] = false;

    $template = createEmployeeProfileTemplate($company, 'No training cert', $configuration);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'EMP-TRC-1',
            'name' => 'Training Cert Employee',
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

    $course = Course::query()->create([
        'name' => 'STCW',
        'is_active' => true,
    ]);

    grantCompanyPermissions($user, $company, [
        'employees.view',
        'employees.training.manage',
    ]);

    $this->actingAs($user)
        ->post(route('organization.employees.training.store', $employee), [
            'course_id' => $course->id,
            'issue_date' => '2024-01-01',
            'institute_center' => 'MTC',
            'certificate' => UploadedFile::fake()->create('cert.pdf', 100, 'application/pdf'),
        ])
        ->assertSessionHasErrors('certificate');
});

test('training store requires certificate when template marks certificate_path required', function () {
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'TRR',
        'name' => 'Training Required Land',
        'dial_code' => '+1',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TRR',
        'name' => 'Training Required Currency',
        'symbol' => '$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Training Required Co',
        'slug' => 'training-required-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'UTC',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $configuration = EmployeeProfileTemplateFieldRegistry::defaultConfiguration();
    $configuration['fields']['employee_trainings']['certificate_path']['visible'] = true;
    $configuration['fields']['employee_trainings']['certificate_path']['required'] = true;

    $template = createEmployeeProfileTemplate($company, 'Required training cert', $configuration);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'EMP-TRR-1',
            'name' => 'Training Required Employee',
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

    $course = Course::query()->create([
        'name' => 'ECDIS',
        'is_active' => true,
    ]);

    grantCompanyPermissions($user, $company, [
        'employees.view',
        'employees.training.manage',
    ]);

    $this->actingAs($user)
        ->post(route('organization.employees.training.store', $employee), [
            'course_id' => $course->id,
            'issue_date' => '2024-01-01',
            'institute_center' => 'MTC',
        ])
        ->assertSessionHasErrors('certificate');
});

test('training store succeeds when template hides optional training fields', function () {
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'TRO',
        'name' => 'Training Optional Land',
        'dial_code' => '+1',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TRO',
        'name' => 'Training Optional Currency',
        'symbol' => '$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Training Optional Co',
        'slug' => 'training-optional-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'UTC',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $configuration = EmployeeProfileTemplateFieldRegistry::defaultConfiguration();

    foreach (['issue_date', 'expiry_date', 'institute_center', 'country_id', 'certificate_path'] as $field) {
        $configuration['fields']['employee_trainings'][$field]['visible'] = false;
        $configuration['fields']['employee_trainings'][$field]['required'] = false;
    }

    $configuration['fields']['employee_trainings']['course_id']['visible'] = true;
    $configuration['fields']['employee_trainings']['course_id']['required'] = false;

    $template = createEmployeeProfileTemplate($company, 'Office training', $configuration);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'EMP-TRO-1',
            'name' => 'Training Optional Employee',
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

    $course = Course::query()->create([
        'name' => 'Office Safety',
        'is_active' => true,
    ]);

    grantCompanyPermissions($user, $company, [
        'employees.view',
        'employees.training.manage',
    ]);

    $this->actingAs($user)
        ->post(route('organization.employees.training.store', $employee), [
            'course_id' => $course->id,
        ])
        ->assertRedirect();

    $training = EmployeeTraining::query()
        ->where('employee_id', $employee->id)
        ->first();

    expect($training)->not->toBeNull()
        ->and($training->course_id)->toBe($course->id)
        ->and($training->issue_date)->toBeNull()
        ->and($training->institute_center)->toBeNull()
        ->and($training->certificate_path)->toBeNull();
});

test('training store rejects empty record when template fields are optional', function () {
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'TRE',
        'name' => 'Training Empty Land',
        'dial_code' => '+1',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TRE',
        'name' => 'Training Empty Currency',
        'symbol' => '$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Training Empty Co',
        'slug' => 'training-empty-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'UTC',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $configuration = EmployeeProfileTemplateFieldRegistry::defaultConfiguration();

    foreach (['issue_date', 'expiry_date', 'institute_center', 'country_id', 'certificate_path'] as $field) {
        $configuration['fields']['employee_trainings'][$field]['visible'] = false;
        $configuration['fields']['employee_trainings'][$field]['required'] = false;
    }

    $configuration['fields']['employee_trainings']['course_id']['visible'] = true;
    $configuration['fields']['employee_trainings']['course_id']['required'] = false;

    $template = createEmployeeProfileTemplate($company, 'Empty training guard', $configuration);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'EMP-TRE-1',
            'name' => 'Training Empty Employee',
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

    grantCompanyPermissions($user, $company, [
        'employees.view',
        'employees.training.manage',
    ]);

    $this->actingAs($user)
        ->post(route('organization.employees.training.store', $employee), [])
        ->assertSessionHasErrors('_');

    expect(EmployeeTraining::query()->where('employee_id', $employee->id)->count())->toBe(0);
});

test('training store rejects hidden field values from template', function () {
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'TRH',
        'name' => 'Training Hidden Land',
        'dial_code' => '+1',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TRH',
        'name' => 'Training Hidden Currency',
        'symbol' => '$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Training Hidden Co',
        'slug' => 'training-hidden-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'UTC',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $configuration = EmployeeProfileTemplateFieldRegistry::defaultConfiguration();
    $configuration['fields']['employee_trainings']['issue_date']['visible'] = false;

    $template = createEmployeeProfileTemplate($company, 'Hidden issue date', $configuration);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'EMP-TRH-1',
            'name' => 'Training Hidden Employee',
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

    $course = Course::query()->create([
        'name' => 'Bridge Team Management',
        'is_active' => true,
    ]);

    grantCompanyPermissions($user, $company, [
        'employees.view',
        'employees.training.manage',
    ]);

    $this->actingAs($user)
        ->post(route('organization.employees.training.store', $employee), [
            'course_id' => $course->id,
            'issue_date' => '2024-01-01',
            'institute_center' => 'MTC',
        ])
        ->assertSessionHasErrors('issue_date');
});
