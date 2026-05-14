<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\OnboardingTemplate;
use App\Models\Position;
use App\Models\User;
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
        'probation_end_date' => null,
        'labor_contract_id' => null,
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get("/organization/employees/{$employee->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employee')
            ->has('employee')
            ->has('contract')
            ->has('documents')
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

    $template = OnboardingTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Standard Onboarding',
        'is_default' => true,
        'tasks' => ['version' => 2, 'stages' => []],
    ]);

    grantCompanyPermissions($user, $company, ['employees.create', 'employees.update', 'employees.delete', 'employees.view']);

    $this->post('/organization/employees', [
        'onboarding_template_id' => $template->id,
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
        'onboarding_template_id' => $template->id,
    ]);

    $this->assertDatabaseHas('employee_documents', [
        'company_id' => $company->id,
        'employee_id' => $employeeId,
        'document_type' => 'passport_copy',
        'issue_date' => '2026-01-01',
        'expiry_date' => '2031-01-01',
        'document_number' => 'P1234567',
    ]);

    $this->put("/organization/employees/{$employeeId}", [
        'employee_no' => 'EMP0002',
        'name' => 'Janet Smith',
        'start_date' => '2026-02-01',
        'contract_type' => 'limited',
        'status' => 'inactive',
        'branch_id' => $branch->id,
        'department_id' => $department->id,
        'position_id' => $position->id,
        'work_email' => 'janet@example.com',
        'phone' => '+971511111111',
    ])->assertRedirect("/organization/employees/{$employeeId}");

    $this->assertDatabaseHas('employees', [
        'id' => $employeeId,
        'name' => 'Janet Smith',
        'status' => 'inactive',
    ]);

    $this->assertDatabaseHas('employee_contracts', [
        'employee_id' => $employeeId,
        'status' => 'active',
        'contract_type' => 'limited',
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
    $this->assertDatabaseMissing('employees', ['id' => $employeeId]);
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

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.import']);

    $csv = "employee_no,name,branch,contract_type,start_date\n"
        ."EMP-IMP-1,Alice Imported,Main Office,unlimited,2026-03-01\n"
        ."EMP-IMP-2,Bob Imported,Unknown Branch,unlimited,2026-03-02\n"
        ."EMP-IMP-3,,Main Office,unlimited,2026-03-03\n";

    $file = UploadedFile::fake()->createWithContent('employees.csv', $csv);

    $preview = $this->post('/organization/employees/import/preview', [
        'file' => $file,
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

    $this->assertDatabaseHas('employee_contracts', [
        'company_id' => $company->id,
        'contract_type' => 'unlimited',
        'start_date' => '2026-03-01',
    ]);
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

    grantCompanyPermissions($user, $company, ['employees.import']);

    $file = UploadedFile::fake()->createWithContent('employees.html', '<html></html>');

    $this->withHeader('Accept', 'application/json')
        ->post('/organization/employees/import/preview', [
            'file' => $file,
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

    grantCompanyPermissions($user, $company, ['employees.import']);

    $csv = "employee_no,name,contract_type,start_date\n";

    foreach (range(1, 1001) as $index) {
        $csv .= "EMP-LIMIT-{$index},Limit Row,unlimited,2026-03-01\n";
    }

    $file = UploadedFile::fake()->createWithContent('employees.csv', $csv);

    $this->withHeader('Accept', 'application/json')
        ->post('/organization/employees/import/preview', [
            'file' => $file,
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
        ]);

    $preview->assertOk();
    expect($preview->json('summary.valid'))->toBe(1);

    $importFile = UploadedFile::fake()->createWithContent('employees.csv', $csv);

    $this->withHeader('Accept', 'application/json')
        ->post('/organization/employees/import', [
            'file' => $importFile,
            'mapping' => $mapping,
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

    grantCompanyPermissions($user, $company, ['employees.import']);

    $csv = "employee_no,name\n"
        ."EMP-DEF-1,No Contract Columns\n";

    $expectedStart = today()->format('Y-m-d');

    $previewFile = UploadedFile::fake()->createWithContent('employees.csv', $csv);

    $preview = $this->withHeader('Accept', 'application/json')
        ->post('/organization/employees/import/preview', [
            'file' => $previewFile,
        ]);

    $preview->assertOk();
    expect($preview->json('errors'))->toHaveCount(0)
        ->and($preview->json('summary.valid'))->toBe(1);

    $importFile = UploadedFile::fake()->createWithContent('employees.csv', $csv);

    $this->withHeader('Accept', 'application/json')
        ->post('/organization/employees/import', [
            'file' => $importFile,
        ])
        ->assertOk();

    $employee = Employee::query()
        ->where('company_id', $company->id)
        ->where('employee_no', 'EMP-DEF-1')
        ->firstOrFail();

    $this->assertDatabaseHas('employee_contracts', [
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'unlimited',
        'start_date' => $expectedStart,
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

    grantCompanyPermissions($user, $company, ['employees.import']);

    $csv = "employee_no,name,contract_type,start_date,iban,account_name,basic_salary,passport_number\n"
        ."EMP-SEC-1,Secure Import,unlimited,2026-03-01,AE070331234567890123456,Secure Import,12000,P1234567\n";

    $previewFile = UploadedFile::fake()->createWithContent('employees.csv', $csv);

    $preview = $this->withHeader('Accept', 'application/json')
        ->post('/organization/employees/import/preview', [
            'file' => $previewFile,
        ]);

    $preview->assertOk();
    expect($preview->json('mapping.iban'))->toBeNull()
        ->and($preview->json('mapping.basic_salary'))->toBeNull()
        ->and($preview->json('mapping.passport_number'))->toBeNull();

    $importFile = UploadedFile::fake()->createWithContent('employees.csv', $csv);

    $this->withHeader('Accept', 'application/json')
        ->post('/organization/employees/import', [
            'file' => $importFile,
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
