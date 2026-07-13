<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Country;
use App\Models\Course;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeTraining;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function makeTrainingBrowseFixtures(): array
{
    $country = Country::query()->firstOrCreate(
        ['code' => 'TR2'],
        ['name' => 'Training Browse Land', 'dial_code' => '+972', 'is_active' => true],
    );

    $currency = Currency::query()->firstOrCreate(
        ['code' => 'TR2'],
        ['name' => 'Training Browse Currency', 'symbol' => 'B$', 'is_active' => true],
    );

    $company = Company::query()->create([
        'name' => 'TrainingBrowseCo',
        'slug' => 'trainingbrowseco-'.uniqid(),
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
        'employee_no' => 'TRN002',
        'name' => 'Browse Employee',
        'status' => 'active',
    ]);

    $course = Course::query()->create([
        'name' => 'Fire Fighting '.uniqid(),
        'is_active' => true,
    ]);

    return compact('company', 'branch', 'employee', 'course');
}

test('guests cannot access employee training browse page', function () {
    ['employee' => $employee] = makeTrainingBrowseFixtures();

    $this->get(route('organization.training.employee', $employee))
        ->assertRedirect(route('login'));
});

test('users without training view cannot access employee training browse page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee] = makeTrainingBrowseFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get(route('organization.training.employee', $employee))->assertForbidden();
});

test('employee training browse page loads trainings for the employee', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'course' => $course] = makeTrainingBrowseFixtures();

    grantCompanyPermissions($user, $company, ['training.view', 'training.create', 'training.update', 'training.delete', 'training.import']);

    EmployeeTraining::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'course_id' => $course->id,
        'issue_date' => now()->subMonths(6)->toDateString(),
        'expiry_date' => now()->addYear()->toDateString(),
        'institute_center' => 'Browse Academy',
        'sort_order' => 0,
    ]);

    $this->get(route('organization.training.employee', $employee))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/training/employee')
            ->where('employee.name', 'Browse Employee')
            ->where('employee.employee_no', 'TRN002')
            ->has('trainings', 1)
            ->where('trainings.0.course_name', $course->name)
            ->where('trainings.0.institute_center', 'Browse Academy')
            ->where('can.view', true)
            ->where('can.create', true)
            ->where('can.update', true)
            ->where('can.delete', true)
            ->where('can.import', true)
            ->has('back.href')
            ->where('back.label', 'Training'));
});

test('employee training browse returns 404 for employee in another company', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeTrainingBrowseFixtures();
    ['employee' => $otherEmployee] = makeTrainingBrowseFixtures();

    grantCompanyPermissions($user, $company, ['training.view']);

    $this->get(route('organization.training.employee', $otherEmployee))->assertNotFound();
});
