<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\OnboardingTemplate;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('authenticated users can access the onboarding pipeline page', function () {
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

    $template = OnboardingTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Standard Onboarding',
        'is_default' => true,
        'tasks' => [
            'version' => 2,
            'stages' => [
                [
                    'key' => 'draft',
                    'label' => 'Draft Stage',
                    'employee_fields' => [['key' => 'first_name', 'required' => true]],
                    'bank_account_fields' => [],
                    'contract_fields' => [],
                    'documents' => []
                ]
            ]
        ]
    ]);

    grantCompanyPermissions($user, $company, ['employees.create']);

    $this->get("/organization/employees/create?template_id={$template->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employee-create')
            ->has('template')
            ->where('template.name', 'Standard Onboarding')
            ->has('options')
        );
});

test('onboarding pipeline correctly saves a complex payload with documents', function () {
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
        'name' => 'HR',
        'code' => 'HR',
        'status' => 'active',
    ]);

    $position = Position::query()->create([
        'company_id' => $company->id,
        'department_id' => $department->id,
        'title' => 'HR Manager',
        'grade' => 'G1',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.create']);

    $payload = [
        'employee_no' => 'PIPE-001',
        'first_name' => 'Pipeline',
        'last_name' => 'Test',
        'work_email' => 'pipeline@example.com',
        'phone' => '+971501111111',
        'branch_id' => $branch->id,
        'department_id' => $department->id,
        'position_id' => $position->id,
        'status' => 'active',
        'start_date' => '2026-05-01',
        'contract_type' => 'unlimited',
        'documents' => [
            [
                'type' => 'id_card',
                'files' => [UploadedFile::fake()->create('id.pdf', 100)],
                'issue_date' => '2026-01-01',
                'expiry_date' => '2030-01-01',
                'document_number' => 'ID-999',
            ]
        ]
    ];

    $this->post('/organization/employees', $payload)
        ->assertRedirect('/organization/employees');

    $employee = Employee::where('employee_no', 'PIPE-001')->first();
    expect($employee)->not->toBeNull();
    expect($employee->first_name)->toBe('Pipeline');

    $this->assertDatabaseHas('employee_documents', [
        'employee_id' => $employee->id,
        'document_type' => 'id_card',
        'document_number' => 'ID-999',
    ]);
});
