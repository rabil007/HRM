<?php

use App\Enums\PayrollPeriodStatus;
use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
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

test('dashboard attendance and leave exclude departments not in Attendance & Leave', function () {
    DashboardAnalytics::$forceCacheInTests = true;

    $fixtures = makeDashboardLeaveVisibilityFixtures();
    extract($fixtures);

    grantCompanyPermissions($user, $company, [
        'attendance.overview.view',
        'employees.view',
    ]);

    AttendanceRecord::query()->create([
        'company_id' => $company->id,
        'employee_id' => $marineEmployee->id,
        'date' => now('Asia/Dubai')->toDateString(),
        'status' => AttendanceRecord::STATUS_PRESENT,
        'source' => AttendanceRecord::SOURCE_MANUAL,
        'hours_worked' => 8,
        'overtime_hours' => 0,
        'late_minutes' => 0,
        'clock_in' => now('Asia/Dubai')->setTime(9, 0),
    ]);
    AttendanceRecord::query()->create([
        'company_id' => $company->id,
        'employee_id' => $officeEmployee->id,
        'date' => now('Asia/Dubai')->toDateString(),
        'status' => AttendanceRecord::STATUS_PRESENT,
        'source' => AttendanceRecord::SOURCE_MANUAL,
        'hours_worked' => 8,
        'overtime_hours' => 0,
        'late_minutes' => 0,
        'clock_in' => now('Asia/Dubai')->setTime(9, 5),
    ]);

    $marineDept->update(['include_in_attendance_leave' => false]);
    DashboardAnalytics::forgetCompany($company->id);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('leave_summary.on_leave_today', 1)
            ->where('attendance_analytics.present_today', 1)
            ->where('attendance_analytics.active_employees', 1)
            ->where('employee_analytics.active', 2));
});

test('personal dashboard respects Attendance & Leave department participation', function () {
    ['company' => $company, 'marineDept' => $marineDept, 'officeDept' => $officeDept, 'marineEmployee' => $marineEmployee, 'officeEmployee' => $officeEmployee] = makeEmployeeVisibilityFixtures();

    $officeDept->update(['include_in_attendance_leave' => true]);
    $marineDept->update(['include_in_attendance_leave' => false]);

    $officeUser = User::factory()->create();
    $marineUser = User::factory()->create();
    $officeEmployee->update(['user_id' => $officeUser->id]);
    $marineEmployee->update(['user_id' => $marineUser->id]);

    LeaveType::query()->create([
        'company_id' => $company->id,
        'name' => 'Annual Leave',
        'code' => 'AL-PERS',
        'color' => '#10b981',
        'status' => 'active',
        'days_per_year' => 30,
    ]);

    AttendanceRecord::query()->create([
        'company_id' => $company->id,
        'employee_id' => $officeEmployee->id,
        'date' => now('Asia/Dubai')->toDateString(),
        'status' => AttendanceRecord::STATUS_PRESENT,
        'source' => AttendanceRecord::SOURCE_MANUAL,
        'hours_worked' => 8,
        'overtime_hours' => 0,
        'late_minutes' => 0,
        'clock_in' => now('Asia/Dubai')->setTime(9, 0),
    ]);
    AttendanceRecord::query()->create([
        'company_id' => $company->id,
        'employee_id' => $marineEmployee->id,
        'date' => now('Asia/Dubai')->toDateString(),
        'status' => AttendanceRecord::STATUS_PRESENT,
        'source' => AttendanceRecord::SOURCE_MANUAL,
        'hours_worked' => 8,
        'overtime_hours' => 0,
        'late_minutes' => 0,
        'clock_in' => now('Asia/Dubai')->setTime(8, 0),
    ]);

    grantCompanyPermissions($officeUser, $company, [], 'office-linked-role');
    grantCompanyPermissions($marineUser, $company, [], 'marine-linked-role');

    $balancesBefore = LeaveBalance::query()
        ->where('company_id', $company->id)
        ->where('employee_id', $marineEmployee->id)
        ->count();

    $this->actingAs($officeUser)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('personal_dashboard.attendance_leave_enabled', true)
            ->where('personal_dashboard.employee.id', $officeEmployee->id)
            ->where('personal_dashboard.attendance_today.status', AttendanceRecord::STATUS_PRESENT)
            ->where('personal_dashboard.my_leave_balances', fn ($balances) => count($balances) >= 1));

    $this->actingAs($marineUser)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('personal_dashboard.has_linked_employee', true)
            ->where('personal_dashboard.attendance_leave_enabled', false)
            ->where('personal_dashboard.employee.id', $marineEmployee->id)
            ->where('personal_dashboard.attendance_today', null)
            ->where('personal_dashboard.recent_attendance', [])
            ->where('personal_dashboard.my_leave_requests', [])
            ->where('personal_dashboard.my_leave_balances', [])
            ->has('personal_dashboard.my_announcements')
            ->has('personal_dashboard.my_expiring_documents')
            ->has('personal_dashboard.my_payslips'));

    expect(LeaveBalance::query()
        ->where('company_id', $company->id)
        ->where('employee_id', $marineEmployee->id)
        ->count())->toBe($balancesBefore);
});

test('department participation mutation invalidates warm user-scoped dashboard cache', function () {
    DashboardAnalytics::$forceCacheInTests = true;

    ['user' => $admin, 'company' => $company, 'marineDept' => $marineDept, 'officeDept' => $officeDept, 'marineEmployee' => $marineEmployee] = makeEmployeeVisibilityFixtures();

    $officeDept->update(['include_in_attendance_leave' => true]);
    $marineDept->update(['include_in_attendance_leave' => true]);

    $linkedUser = User::factory()->create();
    $marineEmployee->update(['user_id' => $linkedUser->id]);

    grantCompanyPermissions($admin, $company, [
        'departments.view',
        'departments.update',
        'attendance.overview.view',
        'attendance.leave-requests.view',
        'employees.view',
    ]);
    grantCompanyPermissions($linkedUser, $company, [], 'linked-employee-role');

    LeaveRequest::forceCreate([
        'company_id' => $company->id,
        'employee_id' => $marineEmployee->id,
        'leave_type_id' => LeaveType::query()->create([
            'company_id' => $company->id,
            'name' => 'Annual Leave',
            'code' => 'AL-CACHE',
            'color' => '#10b981',
            'status' => 'active',
            'days_per_year' => 30,
        ])->id,
        'start_date' => now('Asia/Dubai')->toDateString(),
        'end_date' => now('Asia/Dubai')->toDateString(),
        'total_days' => 1,
        'status' => 'approved',
    ]);

    AttendanceRecord::query()->create([
        'company_id' => $company->id,
        'employee_id' => $marineEmployee->id,
        'date' => now('Asia/Dubai')->toDateString(),
        'status' => AttendanceRecord::STATUS_PRESENT,
        'source' => AttendanceRecord::SOURCE_MANUAL,
        'hours_worked' => 8,
        'overtime_hours' => 0,
        'late_minutes' => 0,
        'clock_in' => now('Asia/Dubai')->setTime(9, 0),
    ]);

    // Warm personal + leave caches while Marine is still included.
    $this->actingAs($linkedUser)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('personal_dashboard.attendance_leave_enabled', true)
            ->where('personal_dashboard.attendance_today.status', AttendanceRecord::STATUS_PRESENT));

    $this->actingAs($admin)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('leave_summary.on_leave_today', 1)
            ->where('attendance_analytics.present_today', 1));

    // Real mutation must invalidate without a manual forgetCompany() call.
    $this->actingAs($admin)
        ->withSession(['current_company_id' => $company->id])
        ->put("/organization/departments/{$marineDept->id}", [
            'name' => $marineDept->name,
            'code' => $marineDept->code,
            'status' => 'active',
            'include_in_attendance_leave' => false,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect((bool) $marineDept->fresh()->include_in_attendance_leave)->toBeFalse();

    $this->actingAs($linkedUser)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('personal_dashboard.attendance_leave_enabled', false)
            ->where('personal_dashboard.attendance_today', null)
            ->where('personal_dashboard.my_leave_balances', []));

    $this->actingAs($admin)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('leave_summary.on_leave_today', 0)
            ->where('attendance_analytics.present_today', 0));
});

test('employee department move mutation invalidates warm personal dashboard cache', function () {
    DashboardAnalytics::$forceCacheInTests = true;

    ['user' => $admin, 'company' => $company, 'marineDept' => $marineDept, 'officeDept' => $officeDept, 'officeEmployee' => $officeEmployee] = makeEmployeeVisibilityFixtures();

    $officeDept->update(['include_in_attendance_leave' => true]);
    $marineDept->update(['include_in_attendance_leave' => false]);

    $linkedUser = User::factory()->create();
    $officeEmployee->update(['user_id' => $linkedUser->id]);

    grantCompanyPermissions($admin, $company, [
        'employees.view',
        'employees.update',
    ]);
    grantCompanyPermissions($linkedUser, $company, [], 'linked-employee-role');

    AttendanceRecord::query()->create([
        'company_id' => $company->id,
        'employee_id' => $officeEmployee->id,
        'date' => now('Asia/Dubai')->toDateString(),
        'status' => AttendanceRecord::STATUS_PRESENT,
        'source' => AttendanceRecord::SOURCE_MANUAL,
        'hours_worked' => 8,
        'overtime_hours' => 0,
        'late_minutes' => 0,
        'clock_in' => now('Asia/Dubai')->setTime(9, 0),
    ]);

    $this->actingAs($linkedUser)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('personal_dashboard.attendance_leave_enabled', true)
            ->where('personal_dashboard.attendance_today.status', AttendanceRecord::STATUS_PRESENT));

    $this->actingAs($admin)
        ->withSession(['current_company_id' => $company->id])
        ->from("/organization/employees/{$officeEmployee->id}")
        ->put("/organization/employees/{$officeEmployee->id}", [
            'name' => $officeEmployee->name,
            'department_id' => $marineDept->id,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect((int) $officeEmployee->fresh()->department_id)->toBe((int) $marineDept->id);

    $this->actingAs($linkedUser)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('personal_dashboard.attendance_leave_enabled', false)
            ->where('personal_dashboard.attendance_today', null)
            ->where('personal_dashboard.my_leave_balances', []));
});
