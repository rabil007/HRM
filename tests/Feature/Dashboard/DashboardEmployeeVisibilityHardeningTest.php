<?php

use App\Enums\PayrollPeriodStatus;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\User;
use App\Support\Dashboard\DashboardAnalytics;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

afterEach(function () {
    DashboardAnalytics::$forceCacheInTests = false;
});

test('dashboard scopes metrics and prevents cache pollution between unrestricted and restricted users', function () {
    DashboardAnalytics::$forceCacheInTests = true;

    ['user' => $unrestrictedUser, 'company' => $company, 'marineDept' => $marineDept, 'officeDept' => $officeDept, 'marineEmployee' => $marineEmployee, 'officeEmployee' => $officeEmployee] = makeEmployeeVisibilityFixtures();

    // Give unrestricted user all dashboard permissions
    grantCompanyPermissions($unrestrictedUser, $company, [
        'employees.view',
        'documents.view',
        'attendance.overview.view',
        'contracts.view',
        'training.view',
        'bank_accounts.view',
        'audit.view',
    ], 'admin-role');

    // Create restricted user restricted to Marine department
    $restrictedUser = User::factory()->create();
    grantCompanyPermissions($restrictedUser, $company, [
        'employees.view',
        'documents.view',
        'attendance.overview.view',
        'contracts.view',
        'training.view',
        'bank_accounts.view',
        'audit.view',
    ]);
    restrictUserToDepartments($restrictedUser, $company, [$marineDept->id]);

    // Create activities
    Activity::query()->create([
        'company_id' => $company->id,
        'log_name' => 'default',
        'description' => 'Updated Marine Crew',
        'subject_type' => Employee::class,
        'subject_id' => $marineEmployee->id,
        'causer_type' => User::class,
        'causer_id' => $unrestrictedUser->id,
        'properties' => ['attributes' => ['name' => 'Marine Crew']],
    ]);

    Activity::query()->create([
        'company_id' => $company->id,
        'log_name' => 'default',
        'description' => 'Updated Office Staff',
        'subject_type' => Employee::class,
        'subject_id' => $officeEmployee->id,
        'causer_type' => User::class,
        'causer_id' => $unrestrictedUser->id,
        'properties' => ['attributes' => ['name' => 'Office Staff']],
    ]);

    // 1. Unrestricted user visits dashboard first -> warms cache with company-wide totals (2 employees)
    $responseUnrestricted = $this->actingAs($unrestrictedUser)
        ->get(route('dashboard'))
        ->assertOk();

    $responseUnrestricted->assertInertia(function (Assert $page) {
        $page->component('dashboard')
            ->where('employee_analytics.total', 2)
            ->where('employee_analytics.active', 2)
            ->has('audit_summary.recent');

        $recentDescriptions = collect($page->toArray()['props']['audit_summary']['recent'])
            ->pluck('description')
            ->all();

        expect($recentDescriptions)
            ->toContain('Updated Marine Crew')
            ->toContain('Updated Office Staff');
    });

    // 2. Restricted user visits dashboard -> MUST NOT see cached company totals (should see 1 Marine employee)
    $responseRestricted = $this->actingAs($restrictedUser)
        ->get(route('dashboard'))
        ->assertOk();

    $responseRestricted->assertInertia(function (Assert $page) {
        $page->component('dashboard')
            ->where('employee_analytics.total', 1)
            ->has('audit_summary.recent');

        $recentDescriptions = collect($page->toArray()['props']['audit_summary']['recent'])
            ->pluck('description')
            ->all();

        expect($recentDescriptions)
            ->toContain('Updated Marine Crew')
            ->not->toContain('Updated Office Staff');
    });

    // 3. Unrestricted user visits again -> MUST still see full company totals (2 employees), not restricted cache
    $responseUnrestrictedAgain = $this->actingAs($unrestrictedUser)
        ->get(route('dashboard'))
        ->assertOk();

    $responseUnrestrictedAgain->assertInertia(function (Assert $page) {
        $page->component('dashboard')
            ->where('employee_analytics.total', 2)
            ->where('employee_analytics.active', 2);
    });
});

function makeDashboardPayrollVisibilityFixtures(): array
{
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'officeDept' => $officeDept, 'marineEmployee' => $marineEmployee, 'officeEmployee' => $officeEmployee] = makeEmployeeVisibilityFixtures();

    grantCompanyPermissions($user, $company, ['payroll.overview.view']);

    $period = PayrollPeriod::factory()->for($company)->create([
        'status' => PayrollPeriodStatus::Paid,
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
        'name' => 'June 2026',
    ]);

    PayrollRecord::factory()->for($company)->for($period, 'period')->for($marineEmployee)->create([
        'net_salary' => 5000,
    ]);
    PayrollRecord::factory()->for($company)->for($period, 'period')->for($officeEmployee)->create([
        'net_salary' => 10000,
    ]);

    return compact('user', 'company', 'marineDept', 'officeDept', 'marineEmployee', 'officeEmployee', 'period');
}

test('dashboard payroll last paid total respects employee visibility for restricted users', function () {
    $fixtures = makeDashboardPayrollVisibilityFixtures();

    restrictUserToDepartments($fixtures['user'], $fixtures['company'], [$fixtures['marineDept']->id]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('payroll_summary.last_paid_period_name', 'June 2026')
            ->where('payroll_summary.last_paid_period_total', 5000));
});

test('dashboard payroll last paid total includes all employees for unrestricted users', function () {
    $fixtures = makeDashboardPayrollVisibilityFixtures();

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('payroll_summary.last_paid_period_total', 15000));
});

test('dashboard payroll last paid total does not include records from another company', function () {
    $fixtures = makeDashboardPayrollVisibilityFixtures();

    $otherCompany = Company::query()->create([
        'name' => 'Other Co',
        'slug' => 'other-co-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => Country::query()->firstOrCreate(['code' => 'OTH'], ['name' => 'Other', 'dial_code' => '+1', 'is_active' => true])->id,
        'currency_id' => Currency::query()->firstOrCreate(['code' => 'OTH'], ['name' => 'Other Cur', 'symbol' => 'O$', 'is_active' => true])->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $otherPeriod = PayrollPeriod::factory()->for($otherCompany)->create([
        'status' => PayrollPeriodStatus::Paid,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'name' => 'July 2026',
    ]);

    $otherEmployee = Employee::factory()->create([
        'company_id' => $otherCompany->id,
        'status' => 'active',
    ]);

    PayrollRecord::factory()->for($otherCompany)->for($otherPeriod, 'period')->for($otherEmployee)->create([
        'net_salary' => 99999,
    ]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('payroll_summary.last_paid_period_name', 'June 2026')
            ->where('payroll_summary.last_paid_period_total', 15000));
});

function makeDashboardLeaveVisibilityFixtures(): array
{
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-21 12:00:00', 'Asia/Dubai'));

    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'officeDept' => $officeDept, 'marineEmployee' => $marineEmployee, 'officeEmployee' => $officeEmployee] = makeEmployeeVisibilityFixtures();

    grantCompanyPermissions($user, $company, ['attendance.overview.view']);

    $leaveType = LeaveType::query()->create([
        'company_id' => $company->id,
        'name' => 'Annual Leave',
        'code' => 'AL-DASH',
        'color' => '#10b981',
        'status' => 'active',
        'days_per_year' => 30,
    ]);

    foreach ([$marineEmployee, $officeEmployee] as $employee) {
        LeaveRequest::forceCreate([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-09-21',
            'end_date' => '2026-09-21',
            'total_days' => 1,
            'status' => 'approved',
        ]);
    }

    return compact('user', 'company', 'marineDept', 'officeDept', 'marineEmployee', 'officeEmployee');
}

test('dashboard leave aggregates respect employee visibility for restricted users', function () {
    $fixtures = makeDashboardLeaveVisibilityFixtures();
    extract($fixtures);

    restrictUserToDepartments($user, $company, [$marineDept->id]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('leave_summary.on_leave_today', 1));
});

test('dashboard leave aggregates include all departments for unrestricted users', function () {
    $fixtures = makeDashboardLeaveVisibilityFixtures();
    extract($fixtures);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('leave_summary.on_leave_today', 2));
});

test('dashboard does not serve stale authorization-derived leave totals after same user visibility narrows', function () {
    DashboardAnalytics::$forceCacheInTests = true;

    $fixtures = makeDashboardLeaveVisibilityFixtures();
    extract($fixtures);

    restrictUserToDepartments($user, $company, [$marineDept->id, $officeDept->id]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('leave_summary.on_leave_today', 2));

    restrictUserToDepartments($user, $company, [$marineDept->id]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('leave_summary.on_leave_today', 1));
});
