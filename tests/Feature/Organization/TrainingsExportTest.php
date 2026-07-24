<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Country;
use App\Models\Course;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeTraining;
use App\Models\User;

function makeTrainingExportFixtures(): array
{
    $country = Country::query()->firstOrCreate(
        ['code' => 'TEX'],
        ['name' => 'Training Export Land', 'dial_code' => '+971', 'is_active' => true],
    );

    $currency = Currency::query()->firstOrCreate(
        ['code' => 'TEX'],
        ['name' => 'Training Export Currency', 'symbol' => 'T$', 'is_active' => true],
    );

    $company = Company::query()->create([
        'name' => 'TrainingExportCo',
        'slug' => 'trainingexportco-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $branch = Branch::query()->create([
        'company_id' => $company->id,
        'name' => 'HQ',
        'code' => 'HQ',
        'status' => 'active',
        'is_headquarters' => true,
    ]);

    $employee = Employee::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'employee_no' => 'TEX001',
        'name' => 'Training Export Employee',
        'status' => 'active',
    ]);

    $course = Course::query()->firstOrCreate(
        ['name' => 'Export Safety Course '.uniqid()],
        ['is_active' => true],
    );

    $training = EmployeeTraining::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'course_id' => $course->id,
        'issue_date' => now()->subYear()->toDateString(),
        'expiry_date' => now()->addDays(10)->toDateString(),
        'institute_center' => 'Export Academy',
        'country_id' => $country->id,
        'sort_order' => 0,
    ]);

    return compact('company', 'branch', 'employee', 'course', 'country', 'training');
}

test('guests cannot access training export', function () {
    $this->get(route('organization.training.export'))->assertRedirect(route('login'));
});

test('users without training view permission cannot export trainings', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeTrainingExportFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get(route('organization.training.export'))->assertForbidden();
});

test('authenticated users with permission can export trainings as csv, excel, and pdf', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeTrainingExportFixtures();

    grantCompanyPermissions($user, $company, ['training.view']);

    $this->get(route('organization.training.export', ['format' => 'csv']))->assertOk();
    $this->get(route('organization.training.export', ['format' => 'xlsx']))->assertOk();
    $this->get(route('organization.training.export', ['format' => 'pdf']))->assertOk();
});

test('training export respects course filter parameter', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'course' => $course, 'country' => $country] = makeTrainingExportFixtures();

    $otherCourse = Course::query()->create([
        'name' => 'Other Export Course '.uniqid(),
        'is_active' => true,
    ]);

    EmployeeTraining::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'course_id' => $otherCourse->id,
        'issue_date' => now()->subMonths(2)->toDateString(),
        'expiry_date' => now()->addMonths(6)->toDateString(),
        'institute_center' => 'Other Academy',
        'country_id' => $country->id,
        'sort_order' => 1,
    ]);

    grantCompanyPermissions($user, $company, ['training.view']);

    $this->get(route('organization.training.export', [
        'format' => 'csv',
        'course_id' => $course->id,
    ]))->assertOk();
});

test('training export can be limited to selected record ids', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    [
        'company' => $company,
        'branch' => $branch,
        'training' => $training,
    ] = makeTrainingExportFixtures();

    $excludedEmployee = Employee::factory()
        ->forCompany($company)
        ->inBranch($branch)
        ->create([
            'employee_no' => 'TEX-EXCLUDED',
            'name' => 'Excluded Training Employee',
        ]);

    EmployeeTraining::factory()->forEmployee($excludedEmployee)->create();

    grantCompanyPermissions($user, $company, ['training.view']);

    $content = $this->get(route('organization.training.export', [
        'format' => 'csv',
        'ids' => (string) $training->id,
    ]))->streamedContent();

    expect($content)
        ->toContain('Training Export Employee')
        ->not->toContain('Excluded Training Employee');
});
