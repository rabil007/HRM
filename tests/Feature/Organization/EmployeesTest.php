<?php

use App\Models\ApprovalLocation;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyVisaType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeProfileTemplate;
use App\Models\Gender;
use App\Models\Position;
use App\Models\Rank;
use App\Models\SssaOption;
use App\Models\User;
use App\Models\VisaType;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateFieldRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

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
        'contract_type' => 'unlimited',
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
                ->has('ranks'),
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
            'labor_card_number',
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
        ->and($profileFields)->not->toContain('rank_id', 'place_of_birth', 'gender_id', 'visa_type_id', 'company_visa_type_id');
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
        'contract_type' => 'unlimited',
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
        'contract_type' => 'unlimited',
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
        'contract_type' => 'unlimited',
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
        'work_email' => 'janet@example.com',
        'phone' => '+971511111111',
    ])->assertRedirect("/organization/employees/{$employeeId}");

    $this->assertDatabaseHas('employees', [
        'id' => $employeeId,
        'name' => 'Janet Smith',
        'status' => 'inactive',
        'rank_id' => $rank->id,
    ]);

    $this->assertDatabaseHas('employee_contracts', [
        'employee_id' => $employeeId,
        'status' => 'active',
        'contract_type' => 'unlimited',
    ]);

    $activity = Activity::query()
        ->where('company_id', $company->id)
        ->where('subject_type', Employee::class)
        ->where('subject_id', $employeeId)
        ->where('event', 'updated')
        ->latest('id')
        ->first();
    expect($activity)->not->toBeNull();

    $this->put("/organization/employees/{$employeeId}/status", [
        'status' => 'active',
    ])->assertRedirect('/organization/employees');

    $this->assertDatabaseHas('employees', [
        'id' => $employeeId,
        'status' => 'active',
    ]);

    $this->delete("/organization/employees/{$employeeId}")->assertRedirect('/organization/employees');
    $this->assertSoftDeleted('employees', ['id' => $employeeId]);
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
    expect($response->headers->get('Content-Type'))->toContain('text/csv');
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

    $csv = "employee_no,name,branch,contract_type,start_date\n"
        ."EMP-IMP-1,Alice Imported,Main Office,unlimited,2026-03-01\n"
        ."EMP-IMP-2,Bob Imported,Unknown Branch,unlimited,2026-03-02\n"
        ."EMP-IMP-3,,Main Office,unlimited,2026-03-03\n";

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

    expect(
        EmployeeContract::query()
            ->where('company_id', $company->id)
            ->where('employee_id', $importedEmployee->id)
            ->where('contract_type', 'unlimited')
            ->value('start_date')
            ?->toDateString(),
    )->toBe('2026-03-01');
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

    $csv = "employee_no,name,contract_type,start_date\n";

    foreach (range(1, 1001) as $index) {
        $csv .= "EMP-LIMIT-{$index},Limit Row,unlimited,2026-03-01\n";
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

    $csv = "Code,Full Name,Agreement,Join\n"
        ."EMP-MAP-1,Manual Mapped,unlimited,2026-03-01\n";

    $mapping = [
        'employee_no' => 'Code',
        'name' => 'Full Name',
        'contract_type' => 'Agreement',
        'start_date' => 'Join',
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
    ]);
});

test('employee import applies contract and start date defaults when omitted', function () {
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

    $expectedStart = today()->format('Y-m-d');

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

    expect(
        EmployeeContract::query()
            ->where('company_id', $company->id)
            ->where('employee_id', $employee->id)
            ->where('contract_type', 'unlimited')
            ->value('start_date')
            ?->toDateString(),
    )->toBe($expectedStart);
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

    $csv = "employee_no,name,contract_type,start_date,iban,account_name,basic_salary,passport_number\n"
        ."EMP-SEC-1,Secure Import,unlimited,2026-03-01,AE070331234567890123456,Secure Import,12000,P1234567\n";

    $previewFile = UploadedFile::fake()->createWithContent('employees.csv', $csv);

    $preview = $this->withHeader('Accept', 'application/json')
        ->post('/organization/employees/import/preview', [
            'file' => $previewFile,
            'employee_profile_template_id' => $template->id,
        ]);

    $preview->assertOk();
    expect($preview->json('mapping.iban'))->toBeNull()
        ->and($preview->json('mapping.basic_salary'))->toBeNull()
        ->and($preview->json('mapping.passport_number'))->toBeNull();

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

    $this->assertDatabaseHas('employee_contracts', [
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'basic_salary' => null,
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
        $configuration['fields']['employee_bank_accounts'][$bankField]['visible'] = false;
    }

    $template = createEmployeeProfileTemplate($company, 'Minimal Import', $configuration);

    grantCompanyPermissions($user, $company, ['employees.import']);

    $response = $this->get("/organization/employees/import/template?template_id={$template->id}");

    $response->assertOk();
    $csv = $response->streamedContent();
    $lines = array_values(array_filter(explode("\n", trim($csv))));
    $headers = str_getcsv($lines[0]);

    expect($headers)->toContain('employee_no', 'name', 'work_email', 'start_date', 'status')
        ->and($headers)->not->toContain('bank', 'iban', 'account_name', 'contract_type');
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
    $lines = array_values(array_filter(explode("\n", trim($response->streamedContent()))));
    $headers = str_getcsv($lines[0]);

    expect($headers)->toContain('address', 'nearest_airport')
        ->and($headers)->not->toContain('work_email', 'phone');
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

    $first = Employee::factory()->forCompany($company)->create(['employee_no' => 'NAV001', 'name' => 'First']);
    $middle = Employee::factory()->forCompany($company)->create(['employee_no' => 'NAV002', 'name' => 'Middle']);
    $last = Employee::factory()->forCompany($company)->create(['employee_no' => 'NAV003', 'name' => 'Last']);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get(route('organization.employees.show', $middle))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('employee_navigation.position', 2)
            ->where('employee_navigation.total', 3)
            ->where('employee_navigation.previous_id', $last->id)
            ->where('employee_navigation.next_id', $first->id)
            ->where('employee_navigation.list_query', []));
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

    $maleGender = Gender::query()->create([
        'name' => 'Male',
        'is_active' => true,
    ]);

    $femaleGender = Gender::query()->create([
        'name' => 'Female',
        'is_active' => true,
    ]);

    $matchedEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'MGF001',
        'manager_id' => $manager->id,
        'gender_id' => $maleGender->id,
    ]);

    Employee::factory()->forCompany($company)->create([
        'employee_no' => 'MGF002',
        'manager_id' => $manager->id,
        'gender_id' => $femaleGender->id,
    ]);

    Employee::factory()->forCompany($company)->create([
        'employee_no' => 'MGF003',
        'manager_id' => null,
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
        'contract_type' => 'unlimited',
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
