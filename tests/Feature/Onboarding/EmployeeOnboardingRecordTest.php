<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\OnboardingRecord;
use App\Models\OnboardingTemplate;
use App\Models\Position;
use App\Models\User;
use Database\Seeders\OnboardingTemplatesSeeder;

test('creating an employee creates an onboarding record in draft stage', function () {
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

    grantCompanyPermissions($user, $company, ['employees.create', 'employees.view']);

    $this->seed(OnboardingTemplatesSeeder::class);

    $defaultTemplateId = OnboardingTemplate::query()
        ->where('company_id', $company->id)
        ->where('is_default', true)
        ->value('id');

    expect($defaultTemplateId)->not->toBeNull();

    $branch = Branch::query()->create([
        'company_id' => $company->id,
        'name' => 'HQ',
        'code' => 'HQ',
        'country' => 'Testland',
        'phone' => '+971500000000',
        'email' => 'hq@example.com',
        'address' => 'Dubai',
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

    $this->post('/organization/employees', [
        'employee_no' => 'EMP1000',
        'first_name' => 'Jane',
        'last_name' => 'Smith',
        'start_date' => '2026-02-01',
        'contract_type' => 'unlimited',
        'status' => 'active',
        'branch_id' => $branch->id,
        'department_id' => $department->id,
        'position_id' => $position->id,
        'work_email' => 'jane@example.com',
        'phone' => '+971500000000',
    ])->assertRedirect('/organization/employees');

    $employeeId = Employee::query()
        ->where('company_id', $company->id)
        ->where('employee_no', 'EMP1000')
        ->value('id');

    expect($employeeId)->not->toBeNull();

    $record = OnboardingRecord::query()
        ->where('company_id', $company->id)
        ->where('employee_id', $employeeId)
        ->first();

    expect($record)->not->toBeNull();
    expect($record->stage)->toBe('draft');
    expect($record->template_id)->toBe($defaultTemplateId);
});
