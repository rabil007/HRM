<?php

use App\Enums\SalaryPaymentMethod;
use App\Models\ApprovalLocation;
use App\Models\Bank;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyVisaType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeContract;
use App\Models\EmployeeProfileTemplate;
use App\Models\Gender;
use App\Models\Position;
use App\Models\Project;
use App\Models\Rank;
use App\Models\SssaOption;
use App\Models\User;
use App\Models\VisaType;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateFieldRegistry;
use App\Support\Employees\EmployeeExportFieldRegistry;
use App\Support\Employees\EmployeeImportTemplateExporter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * @return list<list<string|null>>
 */
function employeeExportCsvRows(TestResponse $response): array
{
    $baseResponse = $response->baseResponse;

    if ($baseResponse instanceof BinaryFileResponse) {
        $content = file_get_contents($baseResponse->getFile()->getPathname());
    } else {
        $content = $response->getContent();
    }

    $content = ltrim((string) $content, "\xEF\xBB\xBF");

    return array_values(array_filter(array_map(
        static fn (string $line): array => str_getcsv($line),
        explode("\n", trim($content)),
    )));
}

test('guests cannot access employees page', function () {
    $this->get('/organization/employees')->assertRedirect(route('login'));
});

test('authenticated users can view employees page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

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

    $company = Company::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get('/organization/employees')->assertOk();
});

test('authenticated users can view an employee details page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

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

    $company = Company::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'EMP0001',
            'name' => 'John Doe',
            'status' => 'active',
        ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'start_date' => '2026-01-01',
        'end_date' => null,
        'labor_contract_id' => null,
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get("/organization/employees/{$employee->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => assertEmployeeProfileRecords(
            $page
                ->component('organization/employee')
                ->has('employee_navigation')
                ->has('employee')
                ->has('employee_tabs')
                ->has('ranks')
                ->has('projects')
                ->has('profile_clients'),
        ));
});

test('employee profile includes profile template when assigned', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TPL',
        'name' => 'Template Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TPL',
        'name' => 'Template Currency',
        'symbol' => 'T',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Template Co',
        'slug' => 'template-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $template = EmployeeProfileTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Office Staff Template',
        'configuration_json' => EmployeeProfileTemplateFieldRegistry::defaultConfiguration(),
    ]);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_profile_template_id' => $template->id,
        ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get("/organization/employees/{$employee->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employee')
            ->where('employee.employee_profile_template.id', $template->id)
            ->where('employee.employee_profile_template.name', 'Office Staff Template')
            ->has('employee_tabs.profile_fields')
        );
});

test('employee profile profile_fields excludes unchecked template fields such as rank', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'OFF',
        'name' => 'Office Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'OFF',
        'name' => 'Office Currency',
        'symbol' => 'O',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Office Co',
        'slug' => 'office-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $template = createEmployeeProfileTemplate(
        $company,
        'Office',
        employeeProfileTemplateWithVisibleEmployeeFields([
            'employee_no',
            'name',
            'work_email',
            'phone',
            'nationality_id',
            'religion_id',
            'marital_status',
            'image',
            'date_of_birth',
            'passport_number',
            'emirates_id',
            'department_id',
            'position_id',
        ]),
    );

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_profile_template_id' => $template->id,
        ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $response = $this->get("/organization/employees/{$employee->id}");

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('organization/employee')
        ->has('employee_tabs.profile_fields'));

    $profileFields = $response->inertiaProps('employee_tabs.profile_fields');

    expect($profileFields)->toBeArray()
        ->and($profileFields)->toContain('work_email', 'religion_id')
        ->and($profileFields)->not->toContain('rank_id', 'project_id', 'client_id', 'place_of_birth', 'gender_id', 'visa_type_id', 'company_visa_type_id');
});

test('employee profile profile_fields includes visa_type_id when enabled in template', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'VIS',
        'name' => 'Visaland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'VIS',
        'name' => 'Visa Currency',
        'symbol' => 'V$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Visa Co',
        'slug' => 'visa-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $template = createEmployeeProfileTemplate(
        $company,
        'Visa Template',
        employeeProfileTemplateWithVisibleEmployeeFields([
            'employee_no',
            'name',
            'visa_type_id',
        ]),
    );

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_profile_template_id' => $template->id,
        ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $response = $this->get("/organization/employees/{$employee->id}");

    $response->assertOk();

    $profileFields = $response->inertiaProps('employee_tabs.profile_fields');

    expect($profileFields)->toContain('visa_type_id');
});

test('employee can be created and updated with visa_type_id', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'V2',
        'name' => 'Visaland Two',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'V2',
        'name' => 'Visa Currency Two',
        'symbol' => 'V2$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Visa Co Two',
        'slug' => 'visa-co-two',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $visaType = VisaType::query()->create([
        'name' => 'Residential Visa',
        'is_active' => true,
    ]);

    $missionVisa = VisaType::query()->create([
        'name' => 'Mission Visa',
        'is_active' => true,
    ]);

    $template = EmployeeProfileTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Minimal',
        'configuration_json' => EmployeeProfileTemplateFieldRegistry::defaultConfiguration(),
    ]);

    grantCompanyPermissions($user, $company, ['employees.create', 'employees.update', 'employees.view']);

    $this->post('/organization/employees', [
        'employee_profile_template_id' => $template->id,
        'employee_no' => 'EMP-VISA',
        'name' => 'Visa Holder',
        'start_date' => '2026-01-01',
        'status' => 'active',
        'visa_type_id' => $visaType->id,
    ])->assertRedirect('/organization/employees');

    $employee = Employee::query()
        ->where('company_id', $company->id)
        ->where('employee_no', 'EMP-VISA')
        ->first();

    expect($employee)->not->toBeNull()
        ->and($employee->visa_type_id)->toBe($visaType->id);

    $this->get("/organization/employees/{$employee->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('employee.visa_type_id', $visaType->id)
            ->where('employee.visa_type_ref.name', 'Residential Visa')
        );

    $this->put("/organization/employees/{$employee->id}", [
        'employee_no' => 'EMP-VISA',
        'name' => 'Visa Holder',
        'visa_type_id' => $missionVisa->id,
    ])->assertRedirect(route('organization.employees.show', $employee));

    expect($employee->fresh()->visa_type_id)->toBe($missionVisa->id);
});

test('employee profile profile_fields includes company_visa_type_id when enabled in template', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'CVS',
        'name' => 'Company Visaland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CVS',
        'name' => 'Company Visa Currency',
        'symbol' => 'CV$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Company Visa Co',
        'slug' => 'company-visa-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $template = createEmployeeProfileTemplate(
        $company,
        'Company Visa Template',
        employeeProfileTemplateWithVisibleEmployeeFields([
            'employee_no',
            'name',
            'company_visa_type_id',
        ]),
    );

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_profile_template_id' => $template->id,
        ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $response = $this->get("/organization/employees/{$employee->id}");

    $response->assertOk();

    $profileFields = $response->inertiaProps('employee_tabs.profile_fields');

    expect($profileFields)->toContain('company_visa_type_id');
});

test('employee can be created and updated with hire_date on the employee record', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'HD1',
        'name' => 'Hireland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'HD1',
        'name' => 'Hire Currency',
        'symbol' => 'HD$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Hire Co',
        'slug' => 'hire-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $template = EmployeeProfileTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Default',
        'configuration_json' => EmployeeProfileTemplateFieldRegistry::defaultConfiguration(),
    ]);

    grantCompanyPermissions($user, $company, ['employees.create', 'employees.update', 'employees.view']);

    $this->post('/organization/employees', [
        'employee_profile_template_id' => $template->id,
        'employee_no' => 'EMP-HIRE',
        'name' => 'Hired Employee',
        'hire_date' => '2024-03-15',
        'start_date' => '2024-04-01',
        'status' => 'active',
    ])->assertRedirect('/organization/employees');

    $employee = Employee::query()
        ->where('company_id', $company->id)
        ->where('employee_no', 'EMP-HIRE')
        ->first();

    expect($employee)->not->toBeNull()
        ->and($employee->hire_date?->toDateString())->toBe('2024-03-15');

    $this->get('/organization/employees')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('employees.0.hire_date', '2024-03-15')
        );

    $this->put("/organization/employees/{$employee->id}", [
        'employee_no' => 'EMP-HIRE',
        'name' => 'Hired Employee',
        'hire_date' => '2024-05-20',
        'status' => 'active',
    ])->assertRedirect("/organization/employees/{$employee->id}");

    expect($employee->fresh()->hire_date?->toDateString())->toBe('2024-05-20');
});

test('employee can be created and updated with company_visa_type_id', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'CV2',
        'name' => 'Company Visaland Two',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CV2',
        'name' => 'Company Visa Currency Two',
        'symbol' => 'CV2$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Company Visa Co Two',
        'slug' => 'company-visa-co-two',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $companyVisaType = CompanyVisaType::query()->create([
        'name' => 'Company Sponsored',
        'is_active' => true,
    ]);

    $groupSponsored = CompanyVisaType::query()->create([
        'name' => 'Group Sponsored',
        'is_active' => true,
    ]);

    $template = EmployeeProfileTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Minimal',
        'configuration_json' => EmployeeProfileTemplateFieldRegistry::defaultConfiguration(),
    ]);

    grantCompanyPermissions($user, $company, ['employees.create', 'employees.update', 'employees.view']);

    $this->post('/organization/employees', [
        'employee_profile_template_id' => $template->id,
        'employee_no' => 'EMP-CVISA',
        'name' => 'Company Visa Holder',
        'start_date' => '2026-01-01',
        'status' => 'active',
        'company_visa_type_id' => $companyVisaType->id,
    ])->assertRedirect('/organization/employees');

    $employee = Employee::query()
        ->where('company_id', $company->id)
        ->where('employee_no', 'EMP-CVISA')
        ->first();

    expect($employee)->not->toBeNull()
        ->and($employee->company_visa_type_id)->toBe($companyVisaType->id);

    $this->get("/organization/employees/{$employee->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('employee.company_visa_type_id', $companyVisaType->id)
            ->where('employee.company_visa_type_ref.name', 'Company Sponsored')
        );

    $this->put("/organization/employees/{$employee->id}", [
        'employee_no' => 'EMP-CVISA',
        'name' => 'Company Visa Holder',
        'company_visa_type_id' => $groupSponsored->id,
    ])->assertRedirect(route('organization.employees.show', $employee));

    expect($employee->fresh()->company_visa_type_id)->toBe($groupSponsored->id);
});

test('employee can update company_visa_type_id when visa_type_id is hidden in template', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'CVH',
        'name' => 'Company Visa Hidden Global',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CVH',
        'name' => 'Company Visa Hidden Currency',
        'symbol' => 'CH$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Company Visa Hidden Co',
        'slug' => 'company-visa-hidden-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $oms = CompanyVisaType::query()->create(['name' => 'OMS', 'is_active' => true]);
    $group = CompanyVisaType::query()->create(['name' => 'Group', 'is_active' => true]);

    VisaType::query()->create(['name' => 'Residential Visa', 'is_active' => true]);

    $template = createEmployeeProfileTemplate(
        $company,
        'Company visa only',
        employeeProfileTemplateWithVisibleEmployeeFields([
            'employee_no',
            'name',
            'company_visa_type_id',
        ]),
    );

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_profile_template_id' => $template->id,
            'company_visa_type_id' => $oms->id,
        ]);

    grantCompanyPermissions($user, $company, ['employees.update', 'employees.view']);

    $this->put("/organization/employees/{$employee->id}", [
        'employee_no' => $employee->employee_no,
        'name' => $employee->name,
        'company_visa_type_id' => $group->id,
    ])->assertRedirect(route('organization.employees.show', $employee));

    expect($employee->fresh()->company_visa_type_id)->toBe($group->id);
});

test('employee salary payment method can be updated', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'PAY',
        'name' => 'Paymentland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'PAY',
        'name' => 'Payment Currency',
        'symbol' => 'P$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Payment Co',
        'slug' => 'payment-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'PAY-001',
            'name' => 'Cash Employee',
            'salary_payment_method' => SalaryPaymentMethod::BankTransfer,
        ]);

    grantCompanyPermissions($user, $company, ['employees.update', 'employees.view']);

    $this->put("/organization/employees/{$employee->id}", [
        'employee_no' => $employee->employee_no,
        'name' => $employee->name,
        'salary_payment_method' => SalaryPaymentMethod::CashAnsari->value,
    ])->assertRedirect(route('organization.employees.show', $employee));

    expect($employee->fresh()->salary_payment_method)->toBe(SalaryPaymentMethod::CashAnsari);
});

test('employee update rejects visa_type_id when hidden in profile template', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'VPH',
        'name' => 'Visa Prohibitedland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'VPH',
        'name' => 'Visa Prohibited Currency',
        'symbol' => 'VP$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Visa Prohibited Co',
        'slug' => 'visa-prohibited-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $visaType = VisaType::query()->create(['name' => 'Mission Visa', 'is_active' => true]);
    $companyVisaType = CompanyVisaType::query()->create(['name' => 'OMS', 'is_active' => true]);

    $template = createEmployeeProfileTemplate(
        $company,
        'Company visa only',
        employeeProfileTemplateWithVisibleEmployeeFields([
            'employee_no',
            'name',
            'company_visa_type_id',
        ]),
    );

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_profile_template_id' => $template->id,
            'company_visa_type_id' => $companyVisaType->id,
        ]);

    grantCompanyPermissions($user, $company, ['employees.update']);

    $this->from("/organization/employees/{$employee->id}")
        ->put("/organization/employees/{$employee->id}", [
            'employee_no' => $employee->employee_no,
            'name' => $employee->name,
            'company_visa_type_id' => $companyVisaType->id,
            'visa_type_id' => $visaType->id,
        ])
        ->assertSessionHasErrors('visa_type_id');
});

test('employee profile includes image and can be updated with a photo', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Storage::fake('public');

    $country = Country::query()->create([
        'code' => 'IMG',
        'name' => 'Imagelands',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'IMG',
        'name' => 'Image Currency',
        'symbol' => 'I$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Photo Co',
        'slug' => 'photo-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'EMP-PHOTO',
            'name' => 'Photo Employee',
            'status' => 'active',
            'image' => null,
        ]);

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.update']);

    $this->get("/organization/employees/{$employee->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('employee.image', null)
        );

    $this->put("/organization/employees/{$employee->id}", [
        'employee_no' => 'EMP-PHOTO',
        'name' => 'Photo Employee',
        'image' => UploadedFile::fake()->image('profile.jpg', 320, 320),
    ])->assertRedirect(route('organization.employees.show', $employee));

    $path = $employee->fresh()->image;
    expect($path)->not->toBeNull();
    Storage::disk('public')->assertExists($path);

    $this->get("/organization/employees/{$employee->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('employee.image', $path)
        );

    $this->put("/organization/employees/{$employee->id}", [
        'employee_no' => 'EMP-PHOTO',
        'name' => 'Photo Employee',
        'remove_image' => true,
    ])->assertRedirect(route('organization.employees.show', $employee));

    expect($employee->fresh()->image)->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

test('authenticated users can create, update, toggle status, and delete an employee', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Storage::fake('public');

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

    $company = Company::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $branch = Branch::query()->create([
        'company_id' => $company->id,
        'name' => 'Main Office',
        'code' => 'DXB',
        'status' => 'active',
        'is_headquarters' => true,
    ]);

    $department = Department::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'name' => 'Engineering',
        'code' => 'ENG',
        'status' => 'active',
    ]);

    $position = Position::query()->create([
        'company_id' => $company->id,
        'department_id' => $department->id,
        'title' => 'Software Engineer',
        'grade' => 'G5',
        'status' => 'active',
    ]);

    $rank = Rank::query()->create([
        'name' => 'Chief Officer',
        'is_active' => true,
    ]);

    $project = Project::query()->create([
        'title' => 'North Field',
        'is_active' => true,
    ]);

    $template = EmployeeProfileTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Standard Onboarding',
        'configuration_json' => EmployeeProfileTemplateFieldRegistry::defaultConfiguration(),
    ]);

    grantCompanyPermissions($user, $company, ['employees.create', 'employees.update', 'employees.delete', 'employees.view']);

    $passportDocType = DocumentType::query()->firstOrCreate(
        ['title' => 'Passport Copy'],
        ['is_active' => true],
    );

    $this->post('/organization/employees', [
        'employee_profile_template_id' => $template->id,
        'employee_no' => 'EMP0002',
        'name' => 'Jane Smith',
        'image' => UploadedFile::fake()->image('avatar.jpg', 300, 300),
        'start_date' => '2026-02-01',
        'status' => 'active',
        'branch_id' => $branch->id,
        'department_id' => $department->id,
        'position_id' => $position->id,
        'work_email' => 'jane@example.com',
        'phone' => '+971500000000',
        'documents' => [
            [
                'type' => 'passport_copy',
                'files' => [UploadedFile::fake()->create('passport.pdf', 10, 'application/pdf')],
                'issue_date' => '2026-01-01',
                'expiry_date' => '2031-01-01',
                'document_number' => 'P1234567',
            ],
        ],
    ])->assertRedirect('/organization/employees');

    $employeeId = Employee::query()
        ->where('company_id', $company->id)
        ->where('employee_no', 'EMP0002')
        ->value('id');

    expect($employeeId)->not->toBeNull();

    $this->assertDatabaseHas('employees', [
        'id' => $employeeId,
        'employee_profile_template_id' => $template->id,
    ]);

    $this->assertDatabaseHas('employee_documents', [
        'company_id' => $company->id,
        'employee_id' => $employeeId,
        'document_type_id' => $passportDocType->id,
        'document_type' => (string) $passportDocType->id,
        'issue_date' => '2026-01-01',
        'expiry_date' => '2031-01-01',
        'document_number' => 'P1234567',
    ]);

    $this->put("/organization/employees/{$employeeId}", [
        'employee_no' => 'EMP0002',
        'name' => 'Janet Smith',
        'status' => 'inactive',
        'branch_id' => $branch->id,
        'department_id' => $department->id,
        'position_id' => $position->id,
        'rank_id' => $rank->id,
        'project_id' => $project->id,
        'work_email' => 'janet@example.com',
        'phone' => '+971511111111',
    ])->assertRedirect("/organization/employees/{$employeeId}");

    $this->assertDatabaseHas('employees', [
        'id' => $employeeId,
        'name' => 'Janet Smith',
        'status' => 'inactive',
        'rank_id' => $rank->id,
        'project_id' => $project->id,
    ]);

    $this->assertDatabaseHas('employee_contracts', [
        'employee_id' => $employeeId,
        'status' => 'active',
    ]);

    $activity = Activity::query()
        ->where('company_id', $company->id)
        ->where('subject_type', Employee::class)
        ->where('subject_id', $employeeId)
        ->where('event', 'updated')
        ->latest('id')
        ->first();
    expect($activity)->not->toBeNull();

    $this->from('/organization/employees')
        ->put("/organization/employees/{$employeeId}/status", [
            'status' => 'active',
        ])->assertRedirect('/organization/employees');

    $this->assertDatabaseHas('employees', [
        'id' => $employeeId,
        'status' => 'active',
    ]);

    $this->delete("/organization/employees/{$employeeId}")->assertRedirect('/organization/employees');
    $this->assertSoftDeleted('employees', ['id' => $employeeId]);
});

test('authenticated users can update employee status from profile and stay on profile', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'STS',
        'name' => 'Status Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'STS',
        'name' => 'Status Currency',
        'symbol' => 'S$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Status Co',
        'slug' => 'status-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.update']);

    $this->from(route('organization.employees.show', $employee))
        ->put(route('organization.employees.status', $employee), [
            'status' => 'on_leave',
        ])
        ->assertRedirect(route('organization.employees.show', $employee));

    expect($employee->fresh()->status)->toBe('on_leave');
});

test('authenticated users can export employees as csv, excel, and pdf', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

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

    $company = Company::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'EMP0003',
            'name' => 'Export User',
            'status' => 'active',
        ]);

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.export']);

    $this->get('/organization/employees/export?format=csv')->assertOk();
    $this->get('/organization/employees/export?format=xlsx')->assertOk();
    $this->get('/organization/employees/export?format=pdf')->assertOk();
});

test('employees index exposes export field options filtered by permissions', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

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

    $company = Company::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get('/organization/employees')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('export_field_options')
            ->where(
                'export_field_options',
                fn ($options) => collect($options)->contains(
                    fn (array $option): bool => $option['key'] === 'passport_number' && $option['allowed'] === false,
                ),
            )
            ->where(
                'export_field_options',
                fn ($options) => collect($options)->contains(
                    fn (array $option): bool => $option['key'] === 'name' && $option['allowed'] === true,
                ),
            ));
});

test('authenticated users can export employees with custom field order via post', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

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

    $company = Company::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'EMP0099',
            'name' => 'Custom Export User',
            'status' => 'active',
        ]);

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.export']);

    $response = $this->postJson('/organization/employees/export', [
        'format' => 'csv',
        'fields' => ['name', 'employee_no', 'id'],
    ]);

    $response->assertOk();

    $rows = employeeExportCsvRows($response);
    $headers = $rows[0];

    expect($headers)->toBe([
        EmployeeExportFieldRegistry::labelFor('name'),
        EmployeeExportFieldRegistry::labelFor('employee_no'),
        EmployeeExportFieldRegistry::labelFor('id'),
    ]);
});

test('post employee export includes contract and primary bank columns when selected', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

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

    $company = Company::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'EMP0100',
            'name' => 'Contract Bank Export',
            'status' => 'active',
        ]);

    EmployeeContract::query()
        ->where('employee_id', $employee->id)
        ->update(['basic_salary' => 7500]);

    $bank = Bank::query()->create([
        'name' => 'Export Test Bank',
        'is_active' => true,
    ]);

    EmployeeBankAccount::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'bank_id' => $bank->id,
        'iban' => 'AE029010101010',
        'account_name' => 'Export Account',
        'is_primary' => true,
    ]);

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.export']);

    $response = $this->postJson('/organization/employees/export', [
        'format' => 'csv',
        'fields' => [
            'name',
            'contract_basic_salary',
            'bank_name',
            'bank_iban',
        ],
    ]);

    $response->assertOk();

    $rows = employeeExportCsvRows($response);
    $data = $rows[1];

    expect($data[0])->toBe('Contract Bank Export');
    expect((float) $data[1])->toBe(7500.0);
    expect($data[2])->toBe('Export Test Bank');
    expect($data[3])->toBe('AE029010101010');
});

test('post employee export rejects unknown field keys', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

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

    $company = Company::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.export']);

    $this->postJson('/organization/employees/export', [
        'format' => 'csv',
        'fields' => ['name', 'unknown_field_key'],
    ])->assertUnprocessable();
});

test('post employee export blocks sensitive identity fields without permission', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

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

    $company = Company::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'EMP0101',
            'name' => 'Sensitive Export',
            'passport_number' => 'P12345678',
            'status' => 'active',
        ]);

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.export']);

    $this->postJson('/organization/employees/export', [
        'format' => 'csv',
        'fields' => ['passport_number'],
    ])->assertStatus(422);
});

test('authenticated users with permission can download the import template', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

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

    $company = Company::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.import']);

    $response = $this->get('/organization/employees/import/template');
    $response->assertOk();
    expect($response->headers->get('Content-Type'))
        ->toContain('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    expect($response->headers->get('content-disposition'))
        ->toContain('employees-import-template.xlsx');
});

test('authenticated users with permission can open the import page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'IPG',
        'name' => 'Import Page Land',
        'dial_code' => '+997',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'IPG',
        'name' => 'Import Page Currency',
        'symbol' => 'I$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Import Page Co',
        'slug' => 'import-page-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.import']);

    $this->get('/organization/employees/import')
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employee-import')
            ->has('template_url')
            ->has('preview_url')
            ->has('import_url')
            ->has('templates')
            ->has('default_template_id')
        );
});

test('authenticated users can preview and import an employees CSV', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

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

    $company = Company::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $branch = Branch::query()->create([
        'company_id' => $company->id,
        'name' => 'Main Office',
        'code' => 'DXB',
        'status' => 'active',
        'is_headquarters' => true,
    ]);

    $template = EmployeeProfileTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Standard',
        'configuration_json' => EmployeeProfileTemplateFieldRegistry::defaultConfiguration(),
    ]);

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.import']);

    $csv = "employee_no,name,branch\n"
        ."EMP-IMP-1,Alice Imported,Main Office\n"
        ."EMP-IMP-2,Bob Imported,Unknown Branch\n"
        ."EMP-IMP-3,,Main Office\n";

    $file = UploadedFile::fake()->createWithContent('employees.csv', $csv);

    $preview = $this->post('/organization/employees/import/preview', [
        'file' => $file,
        'employee_profile_template_id' => $template->id,
    ]);

    $preview->assertOk();
    $previewJson = $preview->json();
    expect($previewJson['summary']['total'])->toBe(3);
    expect(collect($previewJson['errors'])->pluck('row')->unique())
        ->toContain(3)
        ->toContain(4);

    $importFile = UploadedFile::fake()->createWithContent('employees.csv', $csv);

    $this->post('/organization/employees/import', [
        'file' => $importFile,
        'employee_profile_template_id' => $template->id,
    ])->assertRedirect('/organization/employees');

    $this->assertDatabaseHas('employees', [
        'company_id' => $company->id,
        'employee_no' => 'EMP-IMP-1',
        'name' => 'Alice Imported',
        'branch_id' => $branch->id,
    ]);

    $this->assertDatabaseMissing('employees', [
        'company_id' => $company->id,
        'employee_no' => 'EMP-IMP-2',
    ]);

    $importedEmployee = Employee::query()
        ->where('company_id', $company->id)
        ->where('employee_no', 'EMP-IMP-1')
        ->firstOrFail();

    $this->assertDatabaseMissing('employee_contracts', [
        'company_id' => $company->id,
        'employee_id' => $importedEmployee->id,
    ]);
});

test('employee import resolves project name when project_id is enabled in template', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'PRJ',
        'name' => 'Project Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'PRJ',
        'name' => 'Project Currency',
        'symbol' => 'P$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Project Co',
        'slug' => 'project-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $project = Project::query()->create([
        'title' => 'North Field',
        'is_active' => true,
    ]);

    $template = EmployeeProfileTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'With Project',
        'configuration_json' => EmployeeProfileTemplateFieldRegistry::defaultConfiguration(),
    ]);

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.import']);

    $csv = "employee_no,name,project\n"
        ."EMP-PRJ-1,Project Employee,North Field\n";

    $file = UploadedFile::fake()->createWithContent('employees.csv', $csv);

    $this->post('/organization/employees/import', [
        'file' => $file,
        'employee_profile_template_id' => $template->id,
    ])->assertRedirect('/organization/employees');

    $this->assertDatabaseHas('employees', [
        'company_id' => $company->id,
        'employee_no' => 'EMP-PRJ-1',
        'name' => 'Project Employee',
        'project_id' => $project->id,
    ]);
});

test('employee import resolves client name when client_id is enabled in template', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'CLI',
        'name' => 'Client Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CLI',
        'name' => 'Client Currency',
        'symbol' => 'C$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Client Co',
        'slug' => 'client-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $client = Client::query()->create([
        'name' => 'ADNOC Offshore',
        'is_active' => true,
    ]);

    $template = EmployeeProfileTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'With Client',
        'configuration_json' => EmployeeProfileTemplateFieldRegistry::defaultConfiguration(),
    ]);

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.import']);

    $csv = "employee_no,name,client\n"
        ."EMP-CLI-1,Client Employee,ADNOC Offshore\n";

    $file = UploadedFile::fake()->createWithContent('employees.csv', $csv);

    $this->post('/organization/employees/import', [
        'file' => $file,
        'employee_profile_template_id' => $template->id,
    ])->assertRedirect('/organization/employees');

    $this->assertDatabaseHas('employees', [
        'company_id' => $company->id,
        'employee_no' => 'EMP-CLI-1',
        'name' => 'Client Employee',
        'client_id' => $client->id,
    ]);
});

test('employee update persists client_id', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'CLU',
        'name' => 'Client Update Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CLU',
        'name' => 'Client Update Currency',
        'symbol' => 'U$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Client Update Co',
        'slug' => 'client-update-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $client = Client::query()->create([
        'name' => 'North Oil',
        'is_active' => true,
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'EMP-CLU-1',
        'name' => 'Client Worker',
        'client_id' => null,
    ]);

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.update']);

    $this->put("/organization/employees/{$employee->id}", [
        'employee_no' => 'EMP-CLU-1',
        'name' => 'Client Worker',
        'status' => 'active',
        'client_id' => $client->id,
    ])->assertRedirect("/organization/employees/{$employee->id}");

    expect($employee->fresh()->client_id)->toBe($client->id);
});

test('employee import update rejects unknown client values', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'UCB',
        'name' => 'Unknown Client Land',
        'dial_code' => '+980',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'UCB',
        'name' => 'Unknown Client Currency',
        'symbol' => 'B$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Unknown Client Co',
        'slug' => 'unknown-client-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $template = EmployeeProfileTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Unknown Client Template',
        'configuration_json' => EmployeeProfileTemplateFieldRegistry::defaultConfiguration(),
    ]);

    Employee::factory()->forCompany($company)->create([
        'employee_no' => '2025',
        'name' => 'Existing Employee',
    ]);

    grantCompanyPermissions($user, $company, ['employees.import', 'employees.update']);

    $csv = "employee_no,client\n2025,Unknown Client\n";
    $file = UploadedFile::fake()->createWithContent('employees.csv', $csv);

    $preview = $this->post('/organization/employees/import/preview', [
        'file' => $file,
        'employee_profile_template_id' => $template->id,
    ]);

    $preview->assertOk();
    expect(collect($preview->json('errors'))->pluck('field'))->toContain('client');
});

test('employee import updates existing employees when employee number matches', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'UPS',
        'name' => 'Upsert Land',
        'dial_code' => '+983',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'UPS',
        'name' => 'Upsert Currency',
        'symbol' => 'U$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Upsert Co',
        'slug' => 'upsert-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $project = Project::query()->create([
        'title' => 'CREWING',
        'is_active' => true,
    ]);

    $template = EmployeeProfileTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Upsert Template',
        'configuration_json' => EmployeeProfileTemplateFieldRegistry::defaultConfiguration(),
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => '2025',
        'name' => 'VINOD MENON',
        'project_id' => null,
    ]);

    grantCompanyPermissions($user, $company, ['employees.import', 'employees.update']);

    $csv = "employee_no,project\n2025,CREWING\n";

    $file = UploadedFile::fake()->createWithContent('employees.csv', $csv);

    $preview = $this->post('/organization/employees/import/preview', [
        'file' => $file,
        'employee_profile_template_id' => $template->id,
    ]);

    $preview->assertOk();
    expect($preview->json('summary.update'))->toBe(1)
        ->and($preview->json('summary.create'))->toBe(0);

    $importFile = UploadedFile::fake()->createWithContent('employees.csv', $csv);

    $this->post('/organization/employees/import', [
        'file' => $importFile,
        'employee_profile_template_id' => $template->id,
    ])->assertRedirect('/organization/employees');

    expect($employee->fresh())
        ->name->toBe('VINOD MENON')
        ->project_id->toBe($project->id);
});

test('employee import update rows require employees.update permission', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'UPP',
        'name' => 'Upsert Permission Land',
        'dial_code' => '+982',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'UPP',
        'name' => 'Upsert Permission Currency',
        'symbol' => 'P$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Upsert Permission Co',
        'slug' => 'upsert-permission-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $template = EmployeeProfileTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Upsert Permission Template',
        'configuration_json' => EmployeeProfileTemplateFieldRegistry::defaultConfiguration(),
    ]);

    Project::query()->create([
        'title' => 'CREWING',
        'is_active' => true,
    ]);

    Employee::factory()->forCompany($company)->create([
        'employee_no' => '2025',
        'name' => 'Existing Employee',
    ]);

    grantCompanyPermissions($user, $company, ['employees.import']);

    $csv = "employee_no,project\n2025,CREWING\n";
    $file = UploadedFile::fake()->createWithContent('employees.csv', $csv);

    $preview = $this->post('/organization/employees/import/preview', [
        'file' => $file,
        'employee_profile_template_id' => $template->id,
    ]);

    $preview->assertOk();
    expect(collect($preview->json('errors'))->pluck('message'))
        ->toContain('You do not have permission to update existing employees.');
});

test('employee import still requires name when creating a new employee', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'UPN',
        'name' => 'Upsert Name Land',
        'dial_code' => '+981',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'UPN',
        'name' => 'Upsert Name Currency',
        'symbol' => 'N$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Upsert Name Co',
        'slug' => 'upsert-name-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $template = EmployeeProfileTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Upsert Name Template',
        'configuration_json' => EmployeeProfileTemplateFieldRegistry::defaultConfiguration(),
    ]);

    grantCompanyPermissions($user, $company, ['employees.import', 'employees.update']);

    $csv = "employee_no,name\nEMP-NEW-1,\n";
    $file = UploadedFile::fake()->createWithContent('employees.csv', $csv);

    $preview = $this->post('/organization/employees/import/preview', [
        'file' => $file,
        'employee_profile_template_id' => $template->id,
    ]);

    $preview->assertOk();
    expect(collect($preview->json('errors'))->pluck('field'))->toContain('name');
});

test('employee import update rejects unknown project values', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'UPB',
        'name' => 'Upsert Bad Project Land',
        'dial_code' => '+980',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'UPB',
        'name' => 'Upsert Bad Project Currency',
        'symbol' => 'B$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Upsert Bad Project Co',
        'slug' => 'upsert-bad-project-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $template = EmployeeProfileTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Upsert Bad Project Template',
        'configuration_json' => EmployeeProfileTemplateFieldRegistry::defaultConfiguration(),
    ]);

    Employee::factory()->forCompany($company)->create([
        'employee_no' => '2025',
        'name' => 'Existing Employee',
    ]);

    grantCompanyPermissions($user, $company, ['employees.import', 'employees.update']);

    $csv = "employee_no,project\n2025,Unknown Project\n";
    $file = UploadedFile::fake()->createWithContent('employees.csv', $csv);

    $preview = $this->post('/organization/employees/import/preview', [
        'file' => $file,
        'employee_profile_template_id' => $template->id,
    ]);

    $preview->assertOk();
    expect(collect($preview->json('errors'))->pluck('field'))->toContain('project');
});

test('employee import rejects duplicate employee numbers in the same file during upsert', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'UPD',
        'name' => 'Upsert Duplicate Land',
        'dial_code' => '+979',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'UPD',
        'name' => 'Upsert Duplicate Currency',
        'symbol' => 'D$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Upsert Duplicate Co',
        'slug' => 'upsert-duplicate-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $template = EmployeeProfileTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Upsert Duplicate Template',
        'configuration_json' => EmployeeProfileTemplateFieldRegistry::defaultConfiguration(),
    ]);

    Project::query()->create([
        'title' => 'CREWING',
        'is_active' => true,
    ]);

    Employee::factory()->forCompany($company)->create([
        'employee_no' => '2025',
        'name' => 'Existing Employee',
    ]);

    grantCompanyPermissions($user, $company, ['employees.import', 'employees.update']);

    $csv = "employee_no,project\n2025,CREWING\n2025,CREWING\n";
    $file = UploadedFile::fake()->createWithContent('employees.csv', $csv);

    $preview = $this->post('/organization/employees/import/preview', [
        'file' => $file,
        'employee_profile_template_id' => $template->id,
    ]);

    $preview->assertOk();
    expect(collect($preview->json('errors'))->pluck('message')->join(' '))
        ->toContain('Duplicate of row');
});

test('employee import rejects unsupported file types', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'BAD',
        'name' => 'Bad File Land',
        'dial_code' => '+996',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'BAD',
        'name' => 'Bad File Currency',
        'symbol' => 'B$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Bad File Co',
        'slug' => 'bad-file-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $template = EmployeeProfileTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Standard',
        'configuration_json' => EmployeeProfileTemplateFieldRegistry::defaultConfiguration(),
    ]);

    grantCompanyPermissions($user, $company, ['employees.import']);

    $file = UploadedFile::fake()->createWithContent('employees.html', '<html></html>');

    $this->withHeader('Accept', 'application/json')
        ->post('/organization/employees/import/preview', [
            'file' => $file,
            'employee_profile_template_id' => $template->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');
});

test('employee import rejects files over the row limit', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'ROW',
        'name' => 'Row Limit Land',
        'dial_code' => '+995',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'ROW',
        'name' => 'Row Limit Currency',
        'symbol' => 'R$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Row Limit Co',
        'slug' => 'row-limit-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $template = EmployeeProfileTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Standard',
        'configuration_json' => EmployeeProfileTemplateFieldRegistry::defaultConfiguration(),
    ]);

    grantCompanyPermissions($user, $company, ['employees.import']);

    $csv = "employee_no,name\n";

    foreach (range(1, 1001) as $index) {
        $csv .= "EMP-LIMIT-{$index},Limit Row\n";
    }

    $file = UploadedFile::fake()->createWithContent('employees.csv', $csv);

    $this->withHeader('Accept', 'application/json')
        ->post('/organization/employees/import/preview', [
            'file' => $file,
            'employee_profile_template_id' => $template->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');
});

test('employee import accepts manual column mapping', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'MAP',
        'name' => 'Mapping Land',
        'dial_code' => '+994',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'MAP',
        'name' => 'Mapping Currency',
        'symbol' => 'M$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Mapping Co',
        'slug' => 'mapping-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $template = EmployeeProfileTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Standard',
        'configuration_json' => EmployeeProfileTemplateFieldRegistry::defaultConfiguration(),
    ]);

    grantCompanyPermissions($user, $company, ['employees.import']);

    $csv = "Code,Full Name,Work Email\n"
        ."EMP-MAP-1,Manual Mapped,john@example.com\n";

    $mapping = [
        'employee_no' => 'Code',
        'name' => 'Full Name',
        'work_email' => 'Work Email',
    ];

    $previewFile = UploadedFile::fake()->createWithContent('employees.csv', $csv);

    $preview = $this->withHeader('Accept', 'application/json')
        ->post('/organization/employees/import/preview', [
            'file' => $previewFile,
            'mapping' => $mapping,
            'employee_profile_template_id' => $template->id,
        ]);

    $preview->assertOk();
    expect($preview->json('summary.valid'))->toBe(1);

    $importFile = UploadedFile::fake()->createWithContent('employees.csv', $csv);

    $this->withHeader('Accept', 'application/json')
        ->post('/organization/employees/import', [
            'file' => $importFile,
            'mapping' => $mapping,
            'employee_profile_template_id' => $template->id,
        ])
        ->assertOk();

    $this->assertDatabaseHas('employees', [
        'company_id' => $company->id,
        'employee_no' => 'EMP-MAP-1',
        'name' => 'Manual Mapped',
        'work_email' => 'john@example.com',
    ]);
});

test('employee import does not create contracts when contract columns are omitted', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'DEF',
        'name' => 'Default Land',
        'dial_code' => '+992',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'DEF',
        'name' => 'Default Currency',
        'symbol' => 'D$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Default Co',
        'slug' => 'default-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $template = EmployeeProfileTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Standard',
        'configuration_json' => EmployeeProfileTemplateFieldRegistry::defaultConfiguration(),
    ]);

    grantCompanyPermissions($user, $company, ['employees.import']);

    $csv = "employee_no,name\n"
        ."EMP-DEF-1,No Contract Columns\n";

    $previewFile = UploadedFile::fake()->createWithContent('employees.csv', $csv);

    $preview = $this->withHeader('Accept', 'application/json')
        ->post('/organization/employees/import/preview', [
            'file' => $previewFile,
            'employee_profile_template_id' => $template->id,
        ]);

    $preview->assertOk();
    expect($preview->json('errors'))->toHaveCount(0)
        ->and($preview->json('summary.valid'))->toBe(1);

    $importFile = UploadedFile::fake()->createWithContent('employees.csv', $csv);

    $this->withHeader('Accept', 'application/json')
        ->post('/organization/employees/import', [
            'file' => $importFile,
            'employee_profile_template_id' => $template->id,
        ])
        ->assertOk();

    $employee = Employee::query()
        ->where('company_id', $company->id)
        ->where('employee_no', 'EMP-DEF-1')
        ->firstOrFail();

    $this->assertDatabaseMissing('employee_contracts', [
        'company_id' => $company->id,
        'employee_id' => $employee->id,
    ]);
});

test('employee import ignores sensitive fields without extra import permissions', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'SEC',
        'name' => 'Security Land',
        'dial_code' => '+993',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'SEC',
        'name' => 'Security Currency',
        'symbol' => 'S$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Security Co',
        'slug' => 'security-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $template = EmployeeProfileTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Standard',
        'configuration_json' => EmployeeProfileTemplateFieldRegistry::defaultConfiguration(),
    ]);

    grantCompanyPermissions($user, $company, ['employees.import']);

    $csv = "employee_no,name,passport_number\n"
        ."EMP-SEC-1,Secure Import,P1234567\n";

    $previewFile = UploadedFile::fake()->createWithContent('employees.csv', $csv);

    $preview = $this->withHeader('Accept', 'application/json')
        ->post('/organization/employees/import/preview', [
            'file' => $previewFile,
            'employee_profile_template_id' => $template->id,
        ]);

    $preview->assertOk();
    expect($preview->json('mapping.passport_number'))->toBeNull();

    $importFile = UploadedFile::fake()->createWithContent('employees.csv', $csv);

    $this->withHeader('Accept', 'application/json')
        ->post('/organization/employees/import', [
            'file' => $importFile,
            'employee_profile_template_id' => $template->id,
        ])
        ->assertOk();

    $this->assertDatabaseHas('employees', [
        'company_id' => $company->id,
        'employee_no' => 'EMP-SEC-1',
        'passport_number' => null,
    ]);

    $employee = Employee::query()
        ->where('company_id', $company->id)
        ->where('employee_no', 'EMP-SEC-1')
        ->firstOrFail();

    $this->assertDatabaseMissing('employee_bank_accounts', [
        'company_id' => $company->id,
        'employee_id' => $employee->id,
    ]);

    $this->assertDatabaseMissing('employee_contracts', [
        'company_id' => $company->id,
        'employee_id' => $employee->id,
    ]);
});

test('employee import preview accepts request without profile template', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'NTP',
        'name' => 'No Template Land',
        'dial_code' => '+991',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'NTP',
        'name' => 'No Template Currency',
        'symbol' => 'N$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'No Template Co',
        'slug' => 'no-template-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.import']);

    $csv = "employee_no,name\nEMP-NT-1,No Template Employee\n";
    $file = UploadedFile::fake()->createWithContent('employees.csv', $csv);

    $this->withHeader('Accept', 'application/json')
        ->post('/organization/employees/import/preview', [
            'file' => $file,
        ])
        ->assertOk();

    // Template from another company
    $otherCompany = Company::query()->create([
        'name' => 'Other Co',
        'slug' => 'other-co-nt',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $foreignTemplate = createEmployeeProfileTemplate($otherCompany, 'Foreign Template');

    $file2 = UploadedFile::fake()->createWithContent('employees.csv', $csv);

    $this->withHeader('Accept', 'application/json')
        ->post('/organization/employees/import/preview', [
            'file' => $file2,
            'employee_profile_template_id' => $foreignTemplate->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('employee_profile_template_id');
});

test('employee import template download only includes fields from selected profile template', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'ITC',
        'name' => 'Import Template Columns',
        'dial_code' => '+989',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'ITC',
        'name' => 'Import Template Columns Currency',
        'symbol' => 'C$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Import Template Columns Co',
        'slug' => 'import-template-columns-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $configuration = employeeProfileTemplateWithVisibleEmployeeFields([
        'employee_no',
        'name',
        'work_email',
    ]);
    $configuration['fields']['employee_contracts']['start_date']['visible'] = true;
    foreach (array_keys($configuration['fields']['employee_bank_accounts']) as $bankField) {
        $configuration['fields']['employee_bank_accounts'][$bankField]['visible'] = true;
    }

    $template = createEmployeeProfileTemplate($company, 'Minimal Import', $configuration);

    grantCompanyPermissions($user, $company, ['employees.import']);

    $response = $this->get("/organization/employees/import/template?template_id={$template->id}");

    $response->assertOk();
    $headers = employeeImportTemplateHeaders($response);

    expect($headers)->toContain('employee_no', 'name', 'work_email')
        ->and($headers)->not->toContain('bank', 'iban', 'account_name', 'contract_type', 'start_date', 'status');
});

test('employee import template download includes visible personal fields such as address', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'ITC2',
        'name' => 'Import Template Columns 2',
        'dial_code' => '+988',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'ITC2',
        'name' => 'Import Template Columns Currency 2',
        'symbol' => 'C$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Import Template Columns Co 2',
        'slug' => 'import-template-columns-co-2',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $configuration = employeeProfileTemplateWithVisibleEmployeeFields([
        'employee_no',
        'name',
        'address',
        'nearest_airport',
    ]);

    $template = createEmployeeProfileTemplate($company, 'Address Import', $configuration);

    grantCompanyPermissions($user, $company, ['employees.import']);

    $response = $this->get("/organization/employees/import/template?template_id={$template->id}");

    $response->assertOk();
    $headers = employeeImportTemplateHeaders($response);

    expect($headers)->toContain('address', 'nearest_airport')
        ->and($headers)->not->toContain('work_email', 'phone');
});

test('employee import template applies dropdown validation for lookup columns', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'ITD',
        'name' => 'Import Dropdown Land',
        'dial_code' => '+986',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'ITD',
        'name' => 'Import Dropdown Currency',
        'symbol' => 'D$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Import Dropdown Co',
        'slug' => 'import-dropdown-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    Branch::query()->create([
        'company_id' => $company->id,
        'name' => 'Main Office',
        'code' => 'MAIN',
        'status' => 'active',
        'is_headquarters' => true,
    ]);

    Gender::query()->create([
        'name' => 'Male',
        'is_active' => true,
    ]);

    grantCompanyPermissions($user, $company, ['employees.import']);

    $response = $this->get('/organization/employees/import/template');
    $response->assertOk();

    $spreadsheet = employeeImportTemplateSpreadsheet($response);
    $importSheet = $spreadsheet->getSheetByName(EmployeeImportTemplateExporter::IMPORT_SHEET_NAME);
    $optionsSheet = $spreadsheet->getSheetByName(EmployeeImportTemplateExporter::OPTIONS_SHEET_NAME);

    $headers = employeeImportTemplateHeaders($response);
    $branchColumnIndex = array_search('branch', $headers, true);
    $genderColumnIndex = array_search('gender', $headers, true);
    $maritalStatusColumnIndex = array_search('marital_status', $headers, true);

    expect($branchColumnIndex)->not->toBeFalse()
        ->and($genderColumnIndex)->not->toBeFalse()
        ->and($maritalStatusColumnIndex)->not->toBeFalse();

    $branchValidation = $importSheet->getCellByColumnAndRow($branchColumnIndex + 1, 2)->getDataValidation();
    $genderValidation = $importSheet->getCellByColumnAndRow($genderColumnIndex + 1, 2)->getDataValidation();
    $maritalStatusValidation = $importSheet->getCellByColumnAndRow($maritalStatusColumnIndex + 1, 2)->getDataValidation();

    expect($branchValidation->getType())->toBe(DataValidation::TYPE_LIST)
        ->and($branchValidation->getErrorStyle())->toBe(DataValidation::STYLE_INFORMATION)
        ->and($branchValidation->getFormula1())->toContain(EmployeeImportTemplateExporter::OPTIONS_SHEET_NAME)
        ->and($genderValidation->getType())->toBe(DataValidation::TYPE_LIST)
        ->and($genderValidation->getErrorStyle())->toBe(DataValidation::STYLE_INFORMATION)
        ->and($maritalStatusValidation->getType())->toBe(DataValidation::TYPE_LIST)
        ->and($maritalStatusValidation->getFormula1())->toContain(EmployeeImportTemplateExporter::OPTIONS_SHEET_NAME);

    $branchOptionsColumn = null;
    for ($column = 1; $column <= 20; $column++) {
        $columnLetter = Coordinate::stringFromColumnIndex($column);
        if ($optionsSheet->getCell("{$columnLetter}1")->getValue() === 'branch') {
            $branchOptionsColumn = $columnLetter;
            break;
        }
    }

    expect($branchOptionsColumn)->not->toBeNull()
        ->and($optionsSheet->getCell("{$branchOptionsColumn}2")->getValue())->toBe('Main Office')
        ->and($optionsSheet->getSheetState())->toBe('hidden');
});

test('employee import template highlights invalid lookup values with conditional formatting', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'ITH',
        'name' => 'Import Highlight Land',
        'dial_code' => '+984',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'ITH',
        'name' => 'Import Highlight Currency',
        'symbol' => 'H$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Import Highlight Co',
        'slug' => 'import-highlight-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    Branch::query()->create([
        'company_id' => $company->id,
        'name' => 'Main Office',
        'code' => 'MAIN',
        'status' => 'active',
        'is_headquarters' => true,
    ]);

    grantCompanyPermissions($user, $company, ['employees.import']);

    $response = $this->get('/organization/employees/import/template');
    $response->assertOk();

    $spreadsheet = employeeImportTemplateSpreadsheet($response);
    $importSheet = $spreadsheet->getSheetByName(EmployeeImportTemplateExporter::IMPORT_SHEET_NAME);
    $headers = employeeImportTemplateHeaders($response);
    $branchColumnIndex = array_search('branch', $headers, true);

    expect($branchColumnIndex)->not->toBeFalse();

    $branchColumnLetter = Coordinate::stringFromColumnIndex($branchColumnIndex + 1);
    $branchRange = "{$branchColumnLetter}2:{$branchColumnLetter}501";
    $conditionalStyles = $importSheet->getStyle($branchRange)->getConditionalStyles();

    expect($conditionalStyles)->not->toBeEmpty();

    $expression = $conditionalStyles[0];

    expect($expression)->toBeInstanceOf(Conditional::class)
        ->and($expression->getConditionType())->toBe(Conditional::CONDITION_EXPRESSION)
        ->and($expression->getConditions()[0])->toContain('COUNTIF')
        ->and($expression->getConditions()[0])->toContain(EmployeeImportTemplateExporter::OPTIONS_SHEET_NAME);
});

test('employee import template with minimal columns does not apply lookup dropdown validation', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'ITM',
        'name' => 'Import Minimal Template',
        'dial_code' => '+985',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'ITM',
        'name' => 'Import Minimal Template Currency',
        'symbol' => 'M$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Import Minimal Template Co',
        'slug' => 'import-minimal-template-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $configuration = employeeProfileTemplateWithVisibleEmployeeFields([
        'employee_no',
        'name',
    ]);

    $template = createEmployeeProfileTemplate($company, 'Name Only Import', $configuration);

    grantCompanyPermissions($user, $company, ['employees.import']);

    $response = $this->get("/organization/employees/import/template?template_id={$template->id}");
    $response->assertOk();

    $spreadsheet = employeeImportTemplateSpreadsheet($response);
    $importSheet = $spreadsheet->getSheetByName(EmployeeImportTemplateExporter::IMPORT_SHEET_NAME);

    expect(employeeImportTemplateHeaders($response))->toBe(['employee_no', 'name'])
        ->and($importSheet->getCell('A2')->getDataValidation()->getType())->toBe(DataValidation::TYPE_NONE)
        ->and($importSheet->getCell('B2')->getDataValidation()->getType())->toBe(DataValidation::TYPE_NONE);
});

test('employee import preview enforces template required work email', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'IRE',
        'name' => 'Import Required Email',
        'dial_code' => '+987',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'IRE',
        'name' => 'Import Required Email Currency',
        'symbol' => 'C$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Import Required Email Co',
        'slug' => 'import-required-email-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $configuration = employeeProfileTemplateWithVisibleEmployeeFields([
        'employee_no',
        'name',
        'work_email',
    ]);
    $configuration['fields']['employees']['work_email']['required'] = true;

    $template = createEmployeeProfileTemplate($company, 'Work email required', $configuration);

    grantCompanyPermissions($user, $company, ['employees.import']);

    $csv = "employee_no,name,work_email\nEMP-REQ-1,Missing Email,\n";

    $file = UploadedFile::fake()->createWithContent('employees.csv', $csv);

    $preview = $this->post('/organization/employees/import/preview', [
        'file' => $file,
        'employee_profile_template_id' => $template->id,
    ]);

    $preview->assertOk();

    $errors = collect($preview->json('errors'));

    expect($errors->firstWhere('field', 'work_email'))->not->toBeNull();

    $workEmailOption = collect($preview->json('field_options'))->firstWhere('field', 'work_email');

    expect($workEmailOption['required'] ?? false)->toBeTrue();
});

test('employee import assigns employee_profile_template_id to imported employees', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'ATM',
        'name' => 'Assign Template Land',
        'dial_code' => '+990',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'ATM',
        'name' => 'Assign Template Currency',
        'symbol' => 'A$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Assign Template Co',
        'slug' => 'assign-template-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $template = EmployeeProfileTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Office Staff',
        'configuration_json' => EmployeeProfileTemplateFieldRegistry::defaultConfiguration(),
    ]);

    grantCompanyPermissions($user, $company, ['employees.import']);

    $csv = "employee_no,name\nEMP-ATM-1,Template Assigned\n";
    $file = UploadedFile::fake()->createWithContent('employees.csv', $csv);

    $this->withHeader('Accept', 'application/json')
        ->post('/organization/employees/import', [
            'file' => $file,
            'employee_profile_template_id' => $template->id,
        ])
        ->assertOk();

    $this->assertDatabaseHas('employees', [
        'company_id' => $company->id,
        'employee_no' => 'EMP-ATM-1',
        'employee_profile_template_id' => $template->id,
    ]);
});

test('employees index respects per_page query parameter', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TPG',
        'name' => 'Pageland',
        'dial_code' => '+998',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TPG',
        'name' => 'Page Currency',
        'symbol' => 'P$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Pager Co',
        'slug' => 'pager-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get('/organization/employees?per_page=15')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('pagination.per_page', 15)
        );
});

test('employee show includes navigation within unfiltered directory', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'NAV',
        'name' => 'Navland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'NAV',
        'name' => 'Nav Currency',
        'symbol' => 'N$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Nav Co',
        'slug' => 'nav-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $first = Employee::factory()->forCompany($company)->create(['employee_no' => 'NAV001', 'name' => 'Alpha']);
    $middle = Employee::factory()->forCompany($company)->create(['employee_no' => 'NAV002', 'name' => 'Bravo']);
    $last = Employee::factory()->forCompany($company)->create(['employee_no' => 'NAV003', 'name' => 'Charlie']);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get(route('organization.employees.show', $middle))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('employee_navigation.position', 2)
            ->where('employee_navigation.total', 3)
            ->where('employee_navigation.previous_id', $first->id)
            ->where('employee_navigation.next_id', $last->id)
            ->where('employee_navigation.list_query', []));
});

test('employee show navigation orders by name not id', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'NVO',
        'name' => 'Navorder',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'NVO',
        'name' => 'Nav Order Currency',
        'symbol' => 'N$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Nav Order Co',
        'slug' => 'nav-order-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    // Created in reverse alphabetical order so ids do not match name order.
    $zoe = Employee::factory()->forCompany($company)->create(['employee_no' => 'NVO001', 'name' => 'Zoe']);
    $mike = Employee::factory()->forCompany($company)->create(['employee_no' => 'NVO002', 'name' => 'Mike']);
    $adam = Employee::factory()->forCompany($company)->create(['employee_no' => 'NVO003', 'name' => 'Adam']);

    grantCompanyPermissions($user, $company, ['employees.view']);

    // Adam sorts first alphabetically despite having the largest id.
    $this->get(route('organization.employees.show', $adam))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('employee_navigation.position', 1)
            ->where('employee_navigation.total', 3)
            ->where('employee_navigation.previous_id', null)
            ->where('employee_navigation.next_id', $mike->id));

    // Zoe sorts last alphabetically despite having the smallest id.
    $this->get(route('organization.employees.show', $zoe))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('employee_navigation.position', 3)
            ->where('employee_navigation.total', 3)
            ->where('employee_navigation.previous_id', $mike->id)
            ->where('employee_navigation.next_id', null));
});

test('employee show navigation respects branch filter', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'NBF',
        'name' => 'Branch Filterland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'NBF',
        'name' => 'Branch Filter Currency',
        'symbol' => 'B$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Branch Filter Co',
        'slug' => 'branch-filter-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $officeBranch = Branch::query()->create([
        'company_id' => $company->id,
        'name' => 'Office',
        'code' => 'OFF',
        'status' => 'active',
    ]);

    $otherBranch = Branch::query()->create([
        'company_id' => $company->id,
        'name' => 'Remote',
        'code' => 'REM',
        'status' => 'active',
    ]);

    $onlyOfficeEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'BF001',
        'branch_id' => $officeBranch->id,
    ]);

    Employee::factory()->forCompany($company)->create([
        'employee_no' => 'BF002',
        'branch_id' => $otherBranch->id,
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get(route('organization.employees.show', [
        'employee' => $onlyOfficeEmployee,
        'branch_id' => $officeBranch->id,
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('employee_navigation.position', 1)
            ->where('employee_navigation.total', 1)
            ->where('employee_navigation.previous_id', null)
            ->where('employee_navigation.next_id', null)
            ->where('employee_navigation.list_query.branch_id', (string) $officeBranch->id));
});

test('employee show navigation is hidden when employee is outside filtered set', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'NEX',
        'name' => 'Excluded Navland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'NEX',
        'name' => 'Excluded Nav Currency',
        'symbol' => 'E$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Excluded Nav Co',
        'slug' => 'excluded-nav-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $officeBranch = Branch::query()->create([
        'company_id' => $company->id,
        'name' => 'Office',
        'code' => 'OFF2',
        'status' => 'active',
    ]);

    $remoteEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'EX001',
        'branch_id' => null,
    ]);

    Employee::factory()->forCompany($company)->create([
        'employee_no' => 'EX002',
        'branch_id' => $officeBranch->id,
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get(route('organization.employees.show', [
        'employee' => $remoteEmployee,
        'branch_id' => $officeBranch->id,
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('employee_navigation', null));
});

test('employee directory index can be filtered by manager and gender', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'MGF',
        'name' => 'Manager Filter Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'MGF',
        'name' => 'Manager Filter Currency',
        'symbol' => 'M$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Manager Filter Co',
        'slug' => 'manager-filter-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $manager = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'MGR001',
        'name' => 'Team Lead',
    ]);

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Operations',
        'code' => 'OPS',
        'manager_id' => $manager->id,
        'status' => 'active',
    ]);

    $maleGender = Gender::query()->create([
        'name' => 'Male',
        'is_active' => true,
    ]);

    $femaleGender = Gender::query()->create([
        'name' => 'Female',
        'is_active' => true,
    ]);

    $matchedEmployee = Employee::factory()->forCompany($company)->inDepartment($department)->create([
        'employee_no' => 'MGF001',
        'gender_id' => $maleGender->id,
    ]);

    Employee::factory()->forCompany($company)->inDepartment($department)->create([
        'employee_no' => 'MGF002',
        'gender_id' => $femaleGender->id,
    ]);

    Employee::factory()->forCompany($company)->create([
        'employee_no' => 'MGF003',
        'gender_id' => $maleGender->id,
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $response = $this->get('/organization/employees?'.http_build_query([
        'manager_id' => $manager->id,
        'gender_id' => $maleGender->id,
    ]))->assertOk();

    $ids = collect($response->viewData('page')['props']['employees'])->pluck('id')->all();

    expect($ids)->toBe([$matchedEmployee->id]);
});

test('employee profile exposes manager derived from department hierarchy', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = Company::query()->create([
        'name' => 'Derived Manager Co',
        'slug' => 'derived-manager-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => Country::query()->create([
            'code' => 'DMC',
            'name' => 'Derived Manager Country',
            'dial_code' => '+971',
            'is_active' => true,
        ])->id,
        'currency_id' => Currency::query()->create([
            'code' => 'DMC',
            'name' => 'Derived Manager Currency',
            'symbol' => 'D$',
            'is_active' => true,
        ])->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $manager = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'DM200',
        'name' => 'Department Manager',
    ]);

    $parent = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Operations',
        'code' => 'OPS',
        'manager_id' => $manager->id,
        'status' => 'active',
    ]);

    $child = Department::query()->create([
        'company_id' => $company->id,
        'parent_id' => $parent->id,
        'name' => 'IT',
        'code' => 'IT',
        'status' => 'active',
    ]);

    $employee = Employee::factory()->forCompany($company)->inDepartment($child)->create([
        'employee_no' => 'EMP200',
        'name' => 'Child Department Employee',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get("/organization/employees/{$employee->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employee')
            ->where('employee.manager.id', $manager->id)
            ->where('employee.manager.employee_no', 'DM200')
            ->where('employee.manager.name', 'Department Manager')
        );
});

test('employee update ignores manager_id because manager is department derived', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = Company::query()->create([
        'name' => 'Ignore Manager Co',
        'slug' => 'ignore-manager-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => Country::query()->create([
            'code' => 'IMC',
            'name' => 'Ignore Manager Country',
            'dial_code' => '+971',
            'is_active' => true,
        ])->id,
        'currency_id' => Currency::query()->create([
            'code' => 'IMC',
            'name' => 'Ignore Manager Currency',
            'symbol' => 'I$',
            'is_active' => true,
        ])->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $departmentManager = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'DM300',
        'name' => 'Assigned Manager',
    ]);

    $otherEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'OTH300',
        'name' => 'Other Employee',
    ]);

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Finance',
        'code' => 'FIN',
        'manager_id' => $departmentManager->id,
        'status' => 'active',
    ]);

    $employee = Employee::factory()->forCompany($company)->inDepartment($department)->create([
        'employee_no' => 'EMP300',
        'name' => 'Finance Employee',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.update']);

    $this->put("/organization/employees/{$employee->id}", [
        'employee_no' => 'EMP300',
        'name' => 'Finance Employee',
        'manager_id' => $otherEmployee->id,
    ])->assertRedirect(route('organization.employees.show', $employee));

    $this->get("/organization/employees/{$employee->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('employee.manager.id', $departmentManager->id)
        );
});

test('employee directory index can be filtered by approval location and sssa option', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'WAF',
        'name' => 'Work Assignment Filterland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'WAF',
        'name' => 'Work Assignment Currency',
        'symbol' => 'W$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Work Assignment Filter Co',
        'slug' => 'work-assignment-filter-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $lzField = ApprovalLocation::query()->create(['name' => 'LZ Field', 'is_active' => true]);
    $dasIsland = ApprovalLocation::query()->create(['name' => 'Das Island', 'is_active' => true]);
    $supply = SssaOption::query()->create(['name' => 'Supply', 'is_active' => true]);
    $dp2 = SssaOption::query()->create(['name' => 'DP2', 'is_active' => true]);

    $matched = Employee::factory()->forCompany($company)->create(['employee_no' => 'WAF001']);
    $matched->approvalLocations()->sync([$lzField->id]);
    $matched->sssaOptions()->sync([$supply->id]);

    $otherLocation = Employee::factory()->forCompany($company)->create(['employee_no' => 'WAF002']);
    $otherLocation->approvalLocations()->sync([$dasIsland->id]);

    $otherSssa = Employee::factory()->forCompany($company)->create(['employee_no' => 'WAF003']);
    $otherSssa->sssaOptions()->sync([$dp2->id]);

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.update']);

    $byLocation = $this->get('/organization/employees?approval_location_id='.$lzField->id)->assertOk();
    expect(collect($byLocation->viewData('page')['props']['employees'])->pluck('id')->all())
        ->toBe([$matched->id]);

    $bySssa = $this->get('/organization/employees?sssa_option_id='.$supply->id)->assertOk();
    expect(collect($bySssa->viewData('page')['props']['employees'])->pluck('id')->all())
        ->toBe([$matched->id]);

    $this->put("/organization/employees/{$matched->id}", [
        'employee_no' => 'WAF001',
        'name' => $matched->name,
        'approval_location_ids' => [$dasIsland->id],
        'sssa_option_ids' => [$supply->id, $dp2->id],
    ])->assertRedirect(route('organization.employees.show', $matched));

    $matched->load(['approvalLocations', 'sssaOptions']);
    expect($matched->approvalLocations->pluck('id')->all())->toBe([$dasIsland->id])
        ->and(collect($matched->sssaOptions->pluck('id'))->sort()->values()->all())
        ->toEqual(collect([$dp2->id, $supply->id])->sort()->values()->all());
});

test('employee directory index can be filtered by multiple approval locations and sssa options', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'WAF2',
        'name' => 'Work Assignment Filterland 2',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'WAF2',
        'name' => 'Work Assignment Currency 2',
        'symbol' => 'W$2',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Work Assignment Filter Co 2',
        'slug' => 'work-assignment-filter-co-2',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $lzField = ApprovalLocation::query()->create(['name' => 'LZ Field 2', 'is_active' => true]);
    $dasIsland = ApprovalLocation::query()->create(['name' => 'Das Island 2', 'is_active' => true]);
    $supply = SssaOption::query()->create(['name' => 'Supply 2', 'is_active' => true]);
    $dp2 = SssaOption::query()->create(['name' => 'DP2 2', 'is_active' => true]);

    $matched = Employee::factory()->forCompany($company)->create(['employee_no' => 'WAF2001']);
    $matched->approvalLocations()->sync([$lzField->id]);
    $matched->sssaOptions()->sync([$supply->id]);

    $matched2 = Employee::factory()->forCompany($company)->create(['employee_no' => 'WAF2002']);
    $matched2->approvalLocations()->sync([$dasIsland->id]);
    $matched2->sssaOptions()->sync([$dp2->id]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $expectedIds = collect([$matched->id, $matched2->id])->sort()->values()->all();

    $byLocations = $this->get('/organization/employees?approval_location_id='.$lzField->id.','.$dasIsland->id)
        ->assertOk();

    $locationIds = collect($byLocations->viewData('page')['props']['employees'])
        ->pluck('id')
        ->sort()
        ->values()
        ->all();

    expect($locationIds)->toBe($expectedIds);

    $bySssa = $this->get('/organization/employees?sssa_option_id='.$supply->id.','.$dp2->id)
        ->assertOk();

    $sssaIds = collect($bySssa->viewData('page')['props']['employees'])
        ->pluck('id')
        ->sort()
        ->values()
        ->all();

    expect($sssaIds)->toBe($expectedIds);

    $byBoth = $this->get('/organization/employees?approval_location_id='.$lzField->id.','.$dasIsland->id.'&sssa_option_id='.$supply->id.','.$dp2->id)
        ->assertOk();

    $bothIds = collect($byBoth->viewData('page')['props']['employees'])
        ->pluck('id')
        ->sort()
        ->values()
        ->all();

    expect($bothIds)->toBe($expectedIds);
});

test('employee without profile template can assign one', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'APT',
        'name' => 'Assign Template Land',
        'dial_code' => '+1',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'APT',
        'name' => 'Assign Template Currency',
        'symbol' => '$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Assign Template Co',
        'slug' => 'assign-template-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'UTC',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $template = createEmployeeProfileTemplate($company, 'Marine', EmployeeProfileTemplateFieldRegistry::defaultConfiguration());

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'EMP-APT-1',
            'name' => 'No Template Yet',
            'status' => 'active',
            'employee_profile_template_id' => null,
        ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'start_date' => '2026-01-01',
        'end_date' => null,
        'labor_contract_id' => null,
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.update']);

    $this->get(route('organization.employees.show', $employee))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.assign_profile_template', true)
            ->where('employee.employee_profile_template_id', null)
            ->has('profile_templates', 1));

    $this->put(route('organization.employees.profile-template.assign', $employee), [
        'employee_profile_template_id' => $template->id,
    ])
        ->assertRedirect(route('organization.employees.show', $employee));

    expect($employee->fresh()->employee_profile_template_id)->toBe($template->id);
});

test('employee with profile template cannot be reassigned', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'APR',
        'name' => 'Reassign Block Land',
        'dial_code' => '+1',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'APR',
        'name' => 'Reassign Block Currency',
        'symbol' => '$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Reassign Block Co',
        'slug' => 'reassign-block-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'UTC',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $existingTemplate = createEmployeeProfileTemplate($company, 'Existing', EmployeeProfileTemplateFieldRegistry::defaultConfiguration());
    $otherTemplate = createEmployeeProfileTemplate($company, 'Other', EmployeeProfileTemplateFieldRegistry::defaultConfiguration());

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_profile_template_id' => $existingTemplate->id,
        ]);

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.update']);

    $this->put(route('organization.employees.profile-template.assign', $employee), [
        'employee_profile_template_id' => $otherTemplate->id,
    ])
        ->assertSessionHasErrors('employee_profile_template_id');

    expect($employee->fresh()->employee_profile_template_id)->toBe($existingTemplate->id);
});

test('employee update returns validation error when employee number is already used', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'ENU',
        'name' => 'Employee Numberland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'ENU',
        'name' => 'Employee Number Currency',
        'symbol' => 'EN$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Employee Number Co',
        'slug' => 'employee-number-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $existing = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => '1',
            'name' => 'Existing Employee',
        ]);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => '2',
            'name' => 'New Employee',
        ]);

    grantCompanyPermissions($user, $company, ['employees.update']);

    $this->from("/organization/employees/{$employee->id}")
        ->put("/organization/employees/{$employee->id}", [
            'employee_no' => $existing->employee_no,
            'name' => $employee->name,
        ])
        ->assertSessionHasErrors([
            'employee_no' => 'This employee number is already used in your company. Choose a different number.',
        ]);

    expect($employee->fresh()->employee_no)->toBe('2');
});

test('employee update rejects employee number reserved by a soft deleted employee', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'ENS',
        'name' => 'Employee Softland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'ENS',
        'name' => 'Employee Soft Currency',
        'symbol' => 'ES$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Employee Soft Co',
        'slug' => 'employee-soft-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $deleted = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => '1',
            'name' => 'Deleted Employee',
        ]);
    $deleted->delete();

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'DRAFT-NEW',
            'name' => 'Draft Employee',
        ]);

    grantCompanyPermissions($user, $company, ['employees.update']);

    $this->from("/organization/employees/{$employee->id}")
        ->put("/organization/employees/{$employee->id}", [
            'employee_no' => '1',
            'name' => $employee->name,
        ])
        ->assertSessionHasErrors('employee_no');

    expect($employee->fresh()->employee_no)->toBe('DRAFT-NEW');
});

test('employee directory index can be filtered by role', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'ERF',
        'name' => 'Role Filter Land',
        'dial_code' => '+972',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'ERF',
        'name' => 'Role Filter Currency',
        'symbol' => 'R$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Role Filter Co',
        'slug' => 'role-filter-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

    $role = Role::query()->create([
        'company_id' => $company->id,
        'name' => 'Engineer',
        'guard_name' => 'web',
    ]);

    $userWithRole = User::factory()->create(['company_id' => $company->id]);
    $userWithRole->assignRole($role);

    $matchedEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'ERF001',
        'user_id' => $userWithRole->id,
    ]);

    // Create another employee without the role
    $otherUser = User::factory()->create(['company_id' => $company->id]);
    Employee::factory()->forCompany($company)->create([
        'employee_no' => 'ERF002',
        'user_id' => $otherUser->id,
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $response = $this->get('/organization/employees?'.http_build_query([
        'role_id' => $role->id,
    ]))->assertOk();

    $ids = collect($response->viewData('page')['props']['employees'])->pluck('id')->all();

    expect($ids)->toBe([$matchedEmployee->id]);
});
