<?php

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\AttendanceRecord;
use App\Models\Course;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\EmployeeTraining;
use App\Models\LeaveType;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\User;
use App\Support\Employees\SeaServiceDuration;
use Inertia\Testing\AssertableInertia as Assert;

test('attendance create rejects inactive terminated and cross-company employees', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'inactiveEmployee' => $inactive, 'terminatedEmployee' => $terminated] = makeActiveOnlyScopeFixtures();

    grantCompanyPermissions($user, $company, [
        'attendance.records.view',
        'attendance.records.create',
    ]);

    $foreign = Employee::factory()->create(['status' => 'active']);

    foreach ([$inactive, $terminated, $foreign] as $employee) {
        $this->withSession(['current_company_id' => $company->id])
            ->post('/attendance/records', activeEmployeeAttendancePayload($employee))
            ->assertSessionHasErrors('employee_id');
    }

    expect(AttendanceRecord::query()->count())->toBe(0);
});

test('historical attendance remains visible and updatable after the employee is inactivated', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'activeEmployee' => $employee] = makeActiveOnlyScopeFixtures();

    grantCompanyPermissions($user, $company, [
        'attendance.records.view',
        'attendance.records.create',
        'attendance.records.update',
        'attendance.records.manage',
        'employees.update',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post('/attendance/records', activeEmployeeAttendancePayload($employee))
        ->assertRedirect();

    $record = AttendanceRecord::query()->where('employee_id', $employee->id)->first();
    expect($record)->not->toBeNull();

    $this->from(route('organization.employees.show', $employee))
        ->put(route('organization.employees.status', $employee), ['status' => 'inactive'])
        ->assertRedirect();

    expect($employee->fresh()->status)->toBe('inactive');

    $this->withSession(['current_company_id' => $company->id])
        ->get('/attendance/records?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('records', 1)
            ->where('records.0.id', $record->id));

    $this->withSession(['current_company_id' => $company->id])
        ->put("/attendance/records/{$record->id}", activeEmployeeAttendancePayload($employee, [
            'notes' => 'Historical correction',
        ]))
        ->assertRedirect();

    expect($record->fresh()->notes)->toBe('Historical correction');
});

test('leave create rejects inactive employees while historical leave remains listed', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'activeEmployee' => $active, 'inactiveEmployee' => $inactive] = makeActiveOnlyScopeFixtures();

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.view_all',
        'attendance.leave-requests.create',
        'employees.update',
    ]);

    $leaveType = LeaveType::factory()->for($company)->create(['status' => 'active']);

    $this->withSession(['current_company_id' => $company->id])
        ->post('/attendance/leave-requests', [
            'employee_id' => $inactive->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-12',
            'reason' => 'Should fail',
        ])
        ->assertSessionHasErrors('employee_id');

    $leave = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $active->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-03-01',
        'end_date' => '2026-03-03',
        'total_days' => 3,
        'status' => 'approved',
        'reason' => 'Historical leave',
    ]);

    $this->from(route('organization.employees.show', $active))
        ->put(route('organization.employees.status', $active), ['status' => 'terminated'])
        ->assertRedirect();

    $this->withSession(['current_company_id' => $company->id])
        ->get('/attendance/leave-requests?scope=all')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('leave_requests', 1)
            ->where('leave_requests.0.id', $leave->id)
            ->where('leave_requests.0.employee.id', $active->id));
});

test('historical payroll records remain after the employee becomes inactive', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.view', 'employees.update']);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);
    $employee = createOfficeEmployeeWithContract($company, 'OFF-HIST', 10000, 0, 0, 0);

    $record = PayrollRecord::factory()->for($company)->for($period, 'period')->for($employee)->create([
        'payroll_category' => PayrollCategory::Office,
        'gross_salary' => 10000.00,
        'net_salary' => 10000.00,
    ]);

    $this->from(route('organization.employees.show', $employee))
        ->put(route('organization.employees.status', $employee), ['status' => 'inactive'])
        ->assertRedirect();

    expect($employee->fresh()->status)->toBe('inactive')
        ->and($record->fresh())->not->toBeNull();

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', ['payrollPeriod' => $period]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('payroll_records_summary.employee_count', 1)
            ->where('payroll_records.0.id', $record->id));
});

test('user create rejects linking an inactive employee', function () {
    $auth = User::factory()->create();
    $this->actingAs($auth);

    ['company' => $company, 'inactiveEmployee' => $inactive] = makeActiveOnlyScopeFixtures();

    grantCompanyPermissions($auth, $company, ['users.create', 'users.view']);

    $this->post('/organization/users', [
        'name' => 'Inactive Link',
        'email' => 'inactive-link@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role_id' => '',
        'status' => 'active',
        'employee_id' => $inactive->id,
    ])->assertSessionHasErrors('employee_id');

    expect(User::query()->where('email', 'inactive-link@example.com')->exists())->toBeFalse();
});

test('employee status change is rejected while the employee manages a department', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'activeEmployee' => $employee, 'officeDepartment' => $department] = makeActiveOnlyScopeFixtures();

    $department->update(['manager_id' => $employee->id]);

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.update']);

    $this->from(route('organization.employees.show', $employee))
        ->put(route('organization.employees.status', $employee), ['status' => 'inactive'])
        ->assertSessionHasErrors('status');

    expect($employee->fresh()->status)->toBe('active');
});

test('personal dashboard marks a linked inactive employee as not active workforce', function () {
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    $employee->update(['user_id' => $user->id, 'status' => 'inactive']);

    grantCompanyPermissions($user, $company, []);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('personal_dashboard.has_linked_employee', true)
            ->where('personal_dashboard.is_active_workforce', false)
            ->where('personal_dashboard.employee.id', $employee->id));
});

test('training operational summary and list exclude terminated employees', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'activeEmployee' => $active, 'terminatedEmployee' => $terminated] = makeActiveOnlyScopeFixtures();

    grantCompanyPermissions($user, $company, ['training.view']);

    $course = Course::query()->create([
        'name' => 'STCW AO',
        'is_active' => true,
    ]);

    foreach ([$active, $terminated] as $employee) {
        EmployeeTraining::query()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'course_id' => $course->id,
            'issue_date' => now()->subYear()->toDateString(),
            'expiry_date' => now()->addDays(5)->toDateString(),
            'institute_center' => 'Academy',
            'sort_order' => 0,
        ]);
    }

    $this->get(route('organization.training'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.total', 1)
            ->has('trainings', 1)
            ->where('trainings.0.employee_id', $active->id));
});

test('sea services directory keeps terminated employee history', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'terminatedEmployee' => $terminated] = makeActiveOnlyScopeFixtures();

    grantCompanyPermissions($user, $company, ['sea_services.view']);

    $duration = SeaServiceDuration::fromDates('2023-01-01', '2023-06-30');

    EmployeeSeaService::factory()
        ->forEmployee($terminated)
        ->create([
            'start_date' => '2023-01-01',
            'end_date' => '2023-06-30',
            'total_months' => $duration['months'],
            'total_days' => $duration['days'],
            'sort_order' => 0,
        ]);

    $this->get(route('organization.sea-services'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.total', 1)
            ->has('sea_services', 1)
            ->where('sea_services.0.employee_name', 'Terminated Employee'));
});

test('attendance overview this-month operational counts exclude terminated employees', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'activeEmployee' => $active, 'terminatedEmployee' => $terminated] = makeActiveOnlyScopeFixtures();

    grantCompanyPermissions($user, $company, ['attendance.overview.view']);

    foreach ([$active, $terminated] as $employee) {
        AttendanceRecord::query()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'date' => now()->startOfMonth()->toDateString(),
            'status' => AttendanceRecord::STATUS_PRESENT,
            'source' => AttendanceRecord::SOURCE_MANUAL,
            'hours_worked' => 8,
            'overtime_hours' => 0,
            'late_minutes' => 0,
        ]);
    }

    $this->withSession(['current_company_id' => $company->id])
        ->get('/attendance/overview')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.this_month_total', 1)
            ->where('summary.this_month_present', 1));
});
