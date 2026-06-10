<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Course;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeTraining;
use App\Models\User;
use App\Support\EmployeeDocuments\DocumentUploadOptimizer;
use App\Support\EmployeeDocuments\PreparedDocumentUpload;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateFieldRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot manage training records', function () {
    $employee = Employee::factory()->create();

    $this->post(route('organization.employees.training.store', $employee), [
        'course_id' => 1,
        'issue_date' => '2024-01-01',
        'institute_center' => 'MTC',
    ])->assertRedirect(route('login'));

    $this->get(route('organization.employees.training.import.template', $employee))
        ->assertRedirect(route('login'));
});

test('users without permission cannot manage training records', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TRN',
        'name' => 'Trainingland',
        'dial_code' => '+991',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TRN',
        'name' => 'Training Currency',
        'symbol' => 'T$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Training Co',
        'slug' => 'training-co',
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
            'employee_no' => 'EMP9001',
            'name' => 'Trainee',
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

    $course = Course::query()->create([
        'name' => 'STCW Basic Safety',
        'is_active' => true,
    ]);

    $this->post(route('organization.employees.training.store', $employee), [
        'course_id' => $course->id,
        'issue_date' => '2024-01-01',
        'institute_center' => 'MTC',
    ])->assertForbidden();

    $this->get(route('organization.employees.training.import.template', $employee))
        ->assertForbidden();
});

test('authorized users can store update and destroy training with certificate', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TRN',
        'name' => 'Trainingland',
        'dial_code' => '+991',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TRN',
        'name' => 'Training Currency',
        'symbol' => 'T$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Training Co',
        'slug' => 'training-co',
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
            'employee_no' => 'EMP9002',
            'name' => 'Trainee Two',
            'nationality_id' => $country->id,
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

    grantCompanyPermissions($user, $company, [
        'employees.view',
        'employees.training.manage',
    ]);

    $course = Course::query()->create([
        'name' => 'Advanced Fire Fighting',
        'is_active' => true,
    ]);

    $this->post(route('organization.employees.training.store', $employee), [
        'course_id' => $course->id,
        'issue_date' => '2024-11-26',
        'expiry_date' => '2029-11-26',
        'institute_center' => 'BINA SENA MTC',
        'country_id' => $country->id,
        'certificate' => UploadedFile::fake()->create('cert.pdf', 100, 'application/pdf'),
    ])->assertRedirect();

    $training = EmployeeTraining::query()
        ->where('employee_id', $employee->id)
        ->where('course_id', $course->id)
        ->first();

    expect($training)->not->toBeNull();
    expect($training->certificate_path)->not->toBeNull();
    Storage::disk('public')->assertExists($training->certificate_path);

    $updatedCourse = Course::query()->create([
        'name' => 'ECDIS',
        'is_active' => true,
    ]);

    $this->put(route('organization.employees.training.update', [$employee, $training]), [
        'course_id' => $updatedCourse->id,
        'issue_date' => '2025-01-01',
        'expiry_date' => '2030-01-01',
        'institute_center' => 'BINA SANA MTC',
        'country_id' => $country->id,
    ])->assertRedirect();

    $training->refresh();
    expect($training->course_id)->toBe($updatedCourse->id);
    expect($training->institute_center)->toBe('BINA SANA MTC');

    $this->delete(route('organization.employees.training.destroy', [$employee, $training]))
        ->assertRedirect();

    expect(EmployeeTraining::query()->find($training->id))->toBeNull();
});

test('authorized users can bulk store multiple training certificates', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TBK',
        'name' => 'Training Bulk Store Land',
        'dial_code' => '+992',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TBK',
        'name' => 'Training Bulk Store Currency',
        'symbol' => 'K$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Training Bulk Store Co',
        'slug' => 'training-bulk-store-co',
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
            'employee_no' => 'EMP9010',
            'name' => 'Bulk Store Trainee',
            'nationality_id' => $country->id,
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

    grantCompanyPermissions($user, $company, [
        'employees.view',
        'employees.training.manage',
    ]);

    $firstCourse = Course::query()->create([
        'name' => 'GMDSS / GOC',
        'is_active' => true,
    ]);

    $secondCourse = Course::query()->create([
        'name' => 'STCW Basic Safety',
        'is_active' => true,
    ]);

    $this->post(route('organization.employees.training.bulk-store', $employee), [
        'trainings' => [
            [
                'course_id' => $firstCourse->id,
                'issue_date' => '2024-11-26',
                'expiry_date' => '2029-11-26',
                'institute_center' => 'BINA SENA MTC',
                'country_id' => $country->id,
                'certificate' => UploadedFile::fake()->create('first.pdf', 100, 'application/pdf'),
            ],
            [
                'course_id' => $secondCourse->id,
                'issue_date' => '2025-01-15',
                'expiry_date' => '2030-01-15',
                'institute_center' => 'Maritime Academy',
                'country_id' => $country->id,
                'certificate' => UploadedFile::fake()->create('second.pdf', 100, 'application/pdf'),
            ],
        ],
    ])->assertRedirect();

    $trainings = EmployeeTraining::query()
        ->where('employee_id', $employee->id)
        ->orderBy('sort_order')
        ->get();

    expect($trainings)->toHaveCount(2)
        ->and($trainings[0]->course_id)->toBe($firstCourse->id)
        ->and($trainings[1]->course_id)->toBe($secondCourse->id)
        ->and($trainings[0]->certificate_path)->not->toBeNull()
        ->and($trainings[1]->certificate_path)->not->toBeNull();

    Storage::disk('public')->assertExists($trainings[0]->certificate_path);
    Storage::disk('public')->assertExists($trainings[1]->certificate_path);
});

test('bulk store validation errors use indexed keys', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TVE',
        'name' => 'Training Validation Land',
        'dial_code' => '+993',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TVE',
        'name' => 'Training Validation Currency',
        'symbol' => 'V$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Training Validation Co',
        'slug' => 'training-validation-co',
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
            'employee_no' => 'EMP9011',
            'name' => 'Validation Trainee',
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

    grantCompanyPermissions($user, $company, [
        'employees.view',
        'employees.training.manage',
    ]);

    $validCourse = Course::query()->create([
        'name' => 'Valid Course',
        'is_active' => true,
    ]);

    $this->post(route('organization.employees.training.bulk-store', $employee), [
        'trainings' => [
            [
                'course_id' => $validCourse->id,
                'issue_date' => '2024-11-26',
                'institute_center' => 'MTC A',
                'certificate' => UploadedFile::fake()->create('valid.pdf', 100, 'application/pdf'),
            ],
            [
                'course_id' => 999999,
                'issue_date' => '2024-11-26',
                'institute_center' => 'MTC B',
                'certificate' => UploadedFile::fake()->create('invalid.pdf', 100, 'application/pdf'),
            ],
        ],
    ])->assertSessionHasErrors('trainings.1.course_id');

    expect(EmployeeTraining::query()->where('employee_id', $employee->id)->count())->toBe(0);
});

test('storing a training certificate invokes the document upload optimizer', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TOP',
        'name' => 'Training Optimizer Land',
        'dial_code' => '+994',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TOP',
        'name' => 'Training Optimizer Currency',
        'symbol' => 'O$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Training Optimizer Co',
        'slug' => 'training-optimizer-co',
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
            'employee_no' => 'EMP9012',
            'name' => 'Optimizer Trainee',
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

    grantCompanyPermissions($user, $company, [
        'employees.view',
        'employees.training.manage',
    ]);

    $course = Course::query()->create([
        'name' => 'PDF Optimizer Course',
        'is_active' => true,
    ]);

    $certificate = UploadedFile::fake()->create('cert.pdf', 100, 'application/pdf');

    $optimizer = \Mockery::mock(DocumentUploadOptimizer::class);
    $optimizer->shouldReceive('prepare')
        ->once()
        ->with(\Mockery::type(UploadedFile::class))
        ->andReturn(new PreparedDocumentUpload($certificate));

    $this->app->instance(DocumentUploadOptimizer::class, $optimizer);

    $this->post(route('organization.employees.training.store', $employee), [
        'course_id' => $course->id,
        'issue_date' => '2024-11-26',
        'institute_center' => 'Optimizer MTC',
        'certificate' => $certificate,
    ])->assertRedirect();
});

test('csv import appends training rows for the employee', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TRN',
        'name' => 'United Arab Emirates',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TRN',
        'name' => 'Training Currency',
        'symbol' => 'T$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Training Co',
        'slug' => 'training-co-import',
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
            'employee_no' => 'EMP9003',
            'name' => 'Import Trainee',
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

    grantCompanyPermissions($user, $company, [
        'employees.view',
        'employees.training.manage',
    ]);

    Course::query()->create([
        'name' => 'Radar / ARPA',
        'is_active' => true,
    ]);

    $this->get(route('organization.employees.training.import.template', $employee))
        ->assertOk()
        ->assertDownload();

    $csv = "course,issue_date,expiry_date,institute_center,country\n";
    $csv .= "Radar / ARPA,2024-11-26,2029-11-26,BINA SENA MTC,United Arab Emirates\n";

    $this->post(route('organization.employees.training.import', $employee), [
        'file' => UploadedFile::fake()->createWithContent('training.csv', $csv),
    ])->assertRedirect();

    $row = EmployeeTraining::query()
        ->where('employee_id', $employee->id)
        ->whereHas('course', fn ($q) => $q->where('name', 'Radar / ARPA'))
        ->first();

    expect($row)->not->toBeNull();
    expect($row->institute_center)->toBe('BINA SENA MTC');
    expect($row->country_id)->toBe($country->id);
});

test('employee show page includes deferred trainings and courses', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TRN',
        'name' => 'Trainingland',
        'dial_code' => '+991',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TRN',
        'name' => 'Training Currency',
        'symbol' => 'T$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Training Co',
        'slug' => 'training-co-show',
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
            'employee_no' => 'EMP9004',
            'name' => 'Show Trainee',
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

    $course = Course::query()->create([
        'name' => 'GMDSS / GOC',
        'is_active' => true,
    ]);

    $training = EmployeeTraining::factory()
        ->forEmployee($employee)
        ->create([
            'course_id' => $course->id,
            'issue_date' => '2024-02-01',
            'expiry_date' => '2029-02-01',
            'institute_center' => 'Sea School',
            'country_id' => $country->id,
        ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get(route('organization.employees.show', $employee))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => assertEmployeeProfileRecords(
            $page->component('organization/employee')
                ->where('can.training_manage', false),
            fn (Assert $deferred) => $deferred
                ->has('trainings', 1)
                ->where('trainings.0.id', $training->id)
                ->where('trainings.0.course_name', 'GMDSS / GOC')
                ->has('courses'),
        ));
});

test('cannot manage training for employee in another company', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TRN',
        'name' => 'Trainingland',
        'dial_code' => '+991',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TRN',
        'name' => 'Training Currency',
        'symbol' => 'T$',
        'is_active' => true,
    ]);

    $companyA = Company::query()->create([
        'name' => 'Company A',
        'slug' => 'company-a-training',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $companyB = Company::query()->create([
        'name' => 'Company B',
        'slug' => 'company-b-training',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $employeeB = Employee::factory()
        ->forCompany($companyB)
        ->create([
            'employee_no' => 'EMP9005',
            'name' => 'Other Co Trainee',
            'status' => 'active',
        ]);

    grantCompanyPermissions($user, $companyA, ['employees.training.manage']);

    $course = Course::query()->create([
        'name' => 'COC',
        'is_active' => true,
    ]);

    $this->post(route('organization.employees.training.store', $employeeB), [
        'course_id' => $course->id,
        'issue_date' => '2024-01-01',
        'institute_center' => 'MTC',
    ])->assertForbidden();
});

test('training csv import respects template visible fields', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TRI',
        'name' => 'Training Import Land',
        'dial_code' => '+991',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TRI',
        'name' => 'Training Import Currency',
        'symbol' => 'T$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Training Import Co',
        'slug' => 'training-import-template-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $configuration = EmployeeProfileTemplateFieldRegistry::defaultConfiguration();

    foreach (['issue_date', 'expiry_date', 'institute_center', 'country_id', 'certificate_path'] as $field) {
        $configuration['fields']['employee_trainings'][$field]['visible'] = false;
        $configuration['fields']['employee_trainings'][$field]['required'] = false;
    }

    $configuration['fields']['employee_trainings']['course_id']['visible'] = true;
    $configuration['fields']['employee_trainings']['course_id']['required'] = true;

    $template = createEmployeeProfileTemplate($company, 'Course-only import', $configuration);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'EMP9006',
            'name' => 'Import Template Trainee',
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

    Course::query()->create([
        'name' => 'Radar / ARPA',
        'is_active' => true,
    ]);

    $templateResponse = $this->get(route('organization.employees.training.import.template', $employee))
        ->assertOk()
        ->assertDownload();

    expect($templateResponse->getContent())->toBe("course\nSTCW Basic Safety\n");

    $csv = "course\nRadar / ARPA\n";

    $this->post(route('organization.employees.training.import', $employee), [
        'file' => UploadedFile::fake()->createWithContent('training.csv', $csv),
    ])->assertRedirect();

    $row = EmployeeTraining::query()
        ->where('employee_id', $employee->id)
        ->whereHas('course', fn ($q) => $q->where('name', 'Radar / ARPA'))
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->issue_date)->toBeNull()
        ->and($row->institute_center)->toBeNull();
});

test('users with permission can bulk delete training records', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TRB',
        'name' => 'Training Bulk Land',
        'dial_code' => '+990',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TRB',
        'name' => 'Training Bulk Currency',
        'symbol' => 'B$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Training Bulk Co',
        'slug' => 'training-bulk-co',
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
            'employee_no' => 'EMP9007',
            'name' => 'Bulk Trainee',
            'status' => 'active',
        ]);

    grantCompanyPermissions($user, $company, [
        'employees.view',
        'employees.training.manage',
    ]);

    $first = EmployeeTraining::factory()->forEmployee($employee)->create(['sort_order' => 0]);
    $second = EmployeeTraining::factory()->forEmployee($employee)->create(['sort_order' => 1]);
    $third = EmployeeTraining::factory()->forEmployee($employee)->create(['sort_order' => 2]);

    $this->delete(route('organization.employees.training.bulk-destroy', $employee), [
        'training_ids' => [$first->id, $second->id],
    ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->assertSoftDeleted('employee_trainings', ['id' => $first->id]);
    $this->assertSoftDeleted('employee_trainings', ['id' => $second->id]);
    expect(EmployeeTraining::query()->whereKey($third->id)->exists())->toBeTrue();
});

test('bulk delete ignores training records from another employee', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TRO',
        'name' => 'Training Other Land',
        'dial_code' => '+989',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TRO',
        'name' => 'Training Other Currency',
        'symbol' => 'O$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Training Other Co',
        'slug' => 'training-other-co',
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
            'employee_no' => 'EMP9008',
            'name' => 'Own Trainee',
            'status' => 'active',
        ]);

    $otherEmployee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'EMP9009',
            'name' => 'Other Trainee',
            'status' => 'active',
        ]);

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.training.manage']);

    $ownRecord = EmployeeTraining::factory()->forEmployee($employee)->create();
    $otherRecord = EmployeeTraining::factory()->forEmployee($otherEmployee)->create();

    $this->delete(route('organization.employees.training.bulk-destroy', $employee), [
        'training_ids' => [$ownRecord->id, $otherRecord->id],
    ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $this->assertSoftDeleted('employee_trainings', ['id' => $ownRecord->id]);
    expect(EmployeeTraining::query()->whereKey($otherRecord->id)->exists())->toBeTrue();
});

test('users without permission cannot bulk delete training records', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TRF',
        'name' => 'Training Forbidden Land',
        'dial_code' => '+988',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TRF',
        'name' => 'Training Forbidden Currency',
        'symbol' => 'F$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Training Forbidden Co',
        'slug' => 'training-forbidden-co',
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
            'employee_no' => 'EMP9010',
            'name' => 'Forbidden Trainee',
            'status' => 'active',
        ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $record = EmployeeTraining::factory()->forEmployee($employee)->create();

    $this->delete(route('organization.employees.training.bulk-destroy', $employee), [
        'training_ids' => [$record->id],
    ])->assertForbidden();

    expect(EmployeeTraining::query()->whereKey($record->id)->exists())->toBeTrue();
});
