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

function makeTrainingIndexFixtures(): array
{
    $country = Country::query()->firstOrCreate(
        ['code' => 'TR1'],
        ['name' => 'Training Index Land', 'dial_code' => '+971', 'is_active' => true],
    );

    $currency = Currency::query()->firstOrCreate(
        ['code' => 'TR1'],
        ['name' => 'Training Index Currency', 'symbol' => 'T$', 'is_active' => true],
    );

    $company = Company::query()->create([
        'name' => 'TrainingIndexCo',
        'slug' => 'trainingindexco-'.uniqid(),
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
        'employee_no' => 'TRN001',
        'name' => 'Training Index Employee',
        'status' => 'active',
    ]);

    $course = Course::query()->firstOrCreate(
        ['name' => 'STCW Basic Safety '.uniqid()],
        ['is_active' => true],
    );

    return compact('company', 'branch', 'employee', 'course', 'country');
}

test('guests cannot access training index', function () {
    $this->get(route('organization.training'))->assertRedirect(route('login'));
});

test('users with employees view but without training view cannot access training module', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeTrainingIndexFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get(route('organization.training'))->assertForbidden();
});

test('training index returns paginated trainings with expiry summary', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'course' => $course] = makeTrainingIndexFixtures();

    grantCompanyPermissions($user, $company, ['training.view']);

    EmployeeTraining::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'course_id' => $course->id,
        'issue_date' => now()->subYear()->toDateString(),
        'expiry_date' => now()->addDays(5)->toDateString(),
        'institute_center' => 'Safety Academy',
        'sort_order' => 0,
    ]);

    $this->get(route('organization.training'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/training/index')
            ->where('summary.total', 1)
            ->where('summary.expiring_7', 1)
            ->where('expiry', 'all')
            ->where('issue_date', '')
            ->has('trainings', 1)
            ->where('trainings.0.employee_name', 'Training Index Employee')
            ->where('trainings.0.course_name', $course->name)
            ->where('trainings.0.institute_center', 'Safety Academy')
            ->where('trainings.0.expiry_status', 'expiring_7')
            ->where('can.view', true)
            ->where('can.create', false)
            ->where('can.update', false)
            ->where('can.delete', false)
            ->where('can.import', false)
            ->has('courses')
            ->has('countries'));
});

test('training index filters by expiry status', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'course' => $course] = makeTrainingIndexFixtures();

    grantCompanyPermissions($user, $company, ['training.view']);

    EmployeeTraining::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'course_id' => $course->id,
        'issue_date' => now()->subYears(2)->toDateString(),
        'expiry_date' => now()->subDays(10)->toDateString(),
        'institute_center' => 'Expired Institute',
        'sort_order' => 0,
    ]);

    EmployeeTraining::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'course_id' => $course->id,
        'issue_date' => now()->subMonth()->toDateString(),
        'expiry_date' => now()->addDays(60)->toDateString(),
        'institute_center' => 'Valid Institute',
        'sort_order' => 1,
    ]);

    $this->get(route('organization.training', ['expiry' => 'expired']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/training/index')
            ->where('expiry', 'expired')
            ->has('trainings', 1)
            ->where('trainings.0.institute_center', 'Expired Institute'));
});

test('training index filters by issue date', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'course' => $course] = makeTrainingIndexFixtures();

    grantCompanyPermissions($user, $company, ['training.view']);

    EmployeeTraining::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'course_id' => $course->id,
        'issue_date' => '2024-06-15',
        'expiry_date' => now()->addYear()->toDateString(),
        'institute_center' => 'June Institute',
        'sort_order' => 0,
    ]);

    EmployeeTraining::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'course_id' => $course->id,
        'issue_date' => '2024-08-01',
        'expiry_date' => now()->addYear()->toDateString(),
        'institute_center' => 'August Institute',
        'sort_order' => 1,
    ]);

    $this->get(route('organization.training', ['issue_date' => '2024-06-15']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/training/index')
            ->where('issue_date', '2024-06-15')
            ->has('trainings', 1)
            ->where('trainings.0.institute_center', 'June Institute'));
});

test('training index search matches employee name and course', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'course' => $course] = makeTrainingIndexFixtures();

    grantCompanyPermissions($user, $company, ['training.view']);

    EmployeeTraining::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'course_id' => $course->id,
        'issue_date' => now()->subYear()->toDateString(),
        'expiry_date' => now()->addYear()->toDateString(),
        'institute_center' => 'Searchable Center',
        'sort_order' => 0,
    ]);

    $this->get(route('organization.training', ['search' => 'Training Index']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('trainings', 1)
            ->where('trainings.0.employee_name', 'Training Index Employee'));

    $this->get(route('organization.training', ['search' => 'STCW']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('trainings', 1)
            ->where('trainings.0.course_name', $course->name));

    $this->get(route('organization.training', ['search' => 'no-match-xyz']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('trainings', 0));
});

test('training index is scoped to the current company', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'course' => $course] = makeTrainingIndexFixtures();
    ['company' => $otherCompany, 'employee' => $otherEmployee, 'course' => $otherCourse] = makeTrainingIndexFixtures();

    grantCompanyPermissions($user, $company, ['training.view']);

    EmployeeTraining::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'course_id' => $course->id,
        'issue_date' => now()->subYear()->toDateString(),
        'expiry_date' => now()->addYear()->toDateString(),
        'institute_center' => 'Own Company',
        'sort_order' => 0,
    ]);

    EmployeeTraining::query()->create([
        'company_id' => $otherCompany->id,
        'employee_id' => $otherEmployee->id,
        'course_id' => $otherCourse->id,
        'issue_date' => now()->subYear()->toDateString(),
        'expiry_date' => now()->addYear()->toDateString(),
        'institute_center' => 'Other Company',
        'sort_order' => 0,
    ]);

    $this->get(route('organization.training'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('trainings', 1)
            ->where('trainings.0.institute_center', 'Own Company')
            ->where('summary.total', 1));
});
