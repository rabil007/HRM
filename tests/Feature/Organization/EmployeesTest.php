<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\Position;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
            'first_name' => 'John',
            'last_name' => 'Doe',
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

    $this->get("/organization/employees/{$employee->id}")->assertOk();
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

    grantCompanyPermissions($user, $company, ['employees.create', 'employees.update', 'employees.delete', 'employees.view']);

    $this->post('/organization/employees', [
        'employee_no' => 'EMP0002',
        'first_name' => 'Jane',
        'last_name' => 'Smith',
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
        'first_name' => 'Janet',
        'last_name' => 'Smith',
        'start_date' => '2026-02-01',
        'contract_type' => 'limited',
        'status' => 'inactive',
        'branch_id' => $branch->id,
        'department_id' => $department->id,
        'position_id' => $position->id,
        'work_email' => 'janet@example.com',
        'phone' => '+971511111111',
    ])->assertRedirect('/organization/employees');

    $this->assertDatabaseHas('employees', [
        'id' => $employeeId,
        'first_name' => 'Janet',
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
            'first_name' => 'Export',
            'last_name' => 'User',
            'status' => 'active',
        ]);

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.export']);

    $this->get('/organization/employees/export?format=csv')->assertOk();
    $this->get('/organization/employees/export?format=xlsx')->assertOk();
    $this->get('/organization/employees/export?format=pdf')->assertOk();
});
