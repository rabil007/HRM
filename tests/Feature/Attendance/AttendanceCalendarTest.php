<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\LeaveBalanceManager;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{user: User, company: Company}
 */
function makeAttendanceCalendarFixtures(): array
{
    $user = User::factory()->create();
    $country = Country::query()->create([
        'code' => 'AC'.fake()->unique()->numerify('##'),
        'name' => 'Attendance Calendarland',
        'dial_code' => '+999',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'AC'.fake()->unique()->numerify('##'),
        'name' => 'Attendance Calendar Currency',
        'symbol' => 'C$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Calendar Co',
        'slug' => 'calendar-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    DB::table('company_user')->insert([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['user' => $user, 'company' => $company];
}

/**
 * @return array{employee: Employee, leaveType: LeaveType}
 */
function makeAttendanceCalendarActors(Company $company): array
{
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $leaveType = LeaveType::factory()->for($company)->create(['status' => 'active']);

    return ['employee' => $employee, 'leaveType' => $leaveType];
}

test('guests cannot access attendance calendar page', function () {
    $this->get(route('attendance.calendar.index'))->assertRedirect(route('login'));
});

test('authorized users can view attendance calendar page', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceCalendarFixtures();
    ['employee' => $employee, 'leaveType' => $leaveType] = makeAttendanceCalendarActors($company);
    $employee->update(['user_id' => $user->id]);
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['attendance.leave-requests.view']);

    LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'total_days' => 3,
        'status' => 'approved',
        'approved_by' => $user->id,
        'decided_at' => now(),
    ]);

    $this->get(route('attendance.calendar.index', ['year' => 2026]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('attendance/calendar')
            ->where('year', 2026)
            ->has('approved_leaves', 1)
            ->where('approved_leaves.0.employee.id', $employee->id)
            ->where('approved_leaves.0.leave_type.id', $leaveType->id));
});

test('attendance calendar legend includes leave type balance for linked employee', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceCalendarFixtures();
    ['employee' => $employee, 'leaveType' => $leaveType] = makeAttendanceCalendarActors($company);
    $employee->update(['user_id' => $user->id]);
    $leaveType->update(['days_per_year' => 30]);
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['attendance.leave-requests.view']);

    LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'total_days' => 3,
        'status' => 'approved',
        'approved_by' => $user->id,
        'decided_at' => now(),
    ]);

    LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-02',
        'total_days' => 2,
        'status' => 'pending',
    ]);

    app(LeaveBalanceManager::class)->syncEmployeeYear($company->id, $employee->id, 2026);

    $this->get(route('attendance.calendar.index', ['year' => 2026]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('linked_employee_id', $employee->id)
            ->where('selected_employee_id', $employee->id)
            ->where('can_select_employee', false)
            ->has('leave_types', 1)
            ->where('leave_types.0.id', $leaveType->id)
            ->where('leave_types.0.entitled_days', 30)
            ->where('leave_types.0.used_days', 3)
            ->where('leave_types.0.pending_days', 2)
            ->where('leave_types.0.remaining_days', 25));
});

test('attendance calendar shows empty legend for approver without linked employee until employee is selected', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceCalendarFixtures();
    ['employee' => $employee, 'leaveType' => $leaveType] = makeAttendanceCalendarActors($company);
    $leaveType->update(['days_per_year' => 30]);
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.approve',
    ]);

    LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'total_days' => 3,
        'status' => 'approved',
        'approved_by' => $user->id,
        'decided_at' => now(),
    ]);

    app(LeaveBalanceManager::class)->syncEmployeeYear($company->id, $employee->id, 2026);

    $this->get(route('attendance.calendar.index', ['year' => 2026]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('linked_employee_id', null)
            ->where('selected_employee_id', null)
            ->where('can_select_employee', true)
            ->has('approved_leaves', 0)
            ->has('leave_types', 0));

    $this->get(route('attendance.calendar.index', [
        'year' => 2026,
        'employee_id' => $employee->id,
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected_employee_id', $employee->id)
            ->has('approved_leaves', 1)
            ->has('leave_types', 1)
            ->where('leave_types.0.remaining_days', 27));
});

test('attendance calendar exposes pending request count for selected year', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceCalendarFixtures();
    ['employee' => $employee, 'leaveType' => $leaveType] = makeAttendanceCalendarActors($company);
    $employee->update(['user_id' => $user->id]);
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['attendance.leave-requests.view']);

    LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-03',
        'total_days' => 3,
        'status' => 'pending',
    ]);

    LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2027-01-02',
        'end_date' => '2027-01-03',
        'total_days' => 2,
        'status' => 'pending',
    ]);

    $this->get(route('attendance.calendar.index', ['year' => 2026]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('pending_request_count', 1));
});

test('attendance calendar only includes approved leave requests', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceCalendarFixtures();
    ['employee' => $employee, 'leaveType' => $leaveType] = makeAttendanceCalendarActors($company);
    $employee->update(['user_id' => $user->id]);
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['attendance.leave-requests.view']);

    $statuses = ['pending', 'rejected', 'cancelled'];

    foreach ($statuses as $status) {
        LeaveRequest::query()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-02',
            'total_days' => 2,
            'status' => $status,
        ]);
    }

    LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-04-01',
        'end_date' => '2026-04-02',
        'total_days' => 2,
        'status' => 'approved',
        'approved_by' => $user->id,
        'decided_at' => now(),
    ]);

    $this->get(route('attendance.calendar.index', ['year' => 2026]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('approved_leaves', 1)
            ->where('approved_leaves.0.start_date', '2026-04-01'));
});

test('users without approve permission only see their own approved leaves on calendar', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceCalendarFixtures();
    ['employee' => $ownEmployee, 'leaveType' => $leaveType] = makeAttendanceCalendarActors($company);
    ['employee' => $otherEmployee] = makeAttendanceCalendarActors($company);

    $ownEmployee->update(['user_id' => $user->id]);
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['attendance.leave-requests.view']);

    LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $ownEmployee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-05-01',
        'end_date' => '2026-05-02',
        'total_days' => 2,
        'status' => 'approved',
        'approved_by' => $user->id,
        'decided_at' => now(),
    ]);

    LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $otherEmployee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-05-10',
        'end_date' => '2026-05-12',
        'total_days' => 3,
        'status' => 'approved',
        'approved_by' => $user->id,
        'decided_at' => now(),
    ]);

    $this->get(route('attendance.calendar.index', ['year' => 2026]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('approved_leaves', 1)
            ->where('approved_leaves.0.employee.id', $ownEmployee->id));
});

test('users with approve permission default to their own approved leaves on calendar', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceCalendarFixtures();
    ['employee' => $ownEmployee, 'leaveType' => $leaveType] = makeAttendanceCalendarActors($company);
    ['employee' => $otherEmployee] = makeAttendanceCalendarActors($company);

    $ownEmployee->update(['user_id' => $user->id]);
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.approve',
    ]);

    foreach ([$ownEmployee, $otherEmployee] as $employee) {
        LeaveRequest::query()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-02',
            'total_days' => 2,
            'status' => 'approved',
            'approved_by' => $user->id,
            'decided_at' => now(),
        ]);
    }

    $this->get(route('attendance.calendar.index', ['year' => 2026]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected_employee_id', $ownEmployee->id)
            ->where('can_select_employee', true)
            ->has('employees', 2)
            ->has('approved_leaves', 1)
            ->where('approved_leaves.0.employee.id', $ownEmployee->id));

    $this->get(route('attendance.calendar.index', [
        'year' => 2026,
        'employee_id' => $otherEmployee->id,
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected_employee_id', $otherEmployee->id)
            ->has('approved_leaves', 1)
            ->where('approved_leaves.0.employee.id', $otherEmployee->id));
});

test('calendar employee dropdown only lists employees with leave requests', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceCalendarFixtures();
    ['employee' => $employeeWithRequest, 'leaveType' => $leaveType] = makeAttendanceCalendarActors($company);
    Employee::factory()->forCompany($company)->create(['status' => 'active', 'name' => 'No Requests']);

    $employeeWithRequest->update(['user_id' => $user->id]);
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.approve',
    ]);

    LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employeeWithRequest->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-02',
        'total_days' => 2,
        'status' => 'pending',
    ]);

    $this->get(route('attendance.calendar.index', ['year' => 2026]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 1)
            ->where('employees.0.id', $employeeWithRequest->id));
});

test('calendar honors employee_id for inactive employees without leave requests', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceCalendarFixtures();
    ['employee' => $linkedEmployee, 'leaveType' => $leaveType] = makeAttendanceCalendarActors($company);
    $inactiveEmployee = Employee::factory()->forCompany($company)->create([
        'status' => 'inactive',
        'name' => 'Alice Tech',
    ]);

    $linkedEmployee->update(['user_id' => $user->id]);
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.approve',
    ]);

    LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $linkedEmployee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-05',
        'total_days' => 5,
        'status' => 'approved',
        'approved_by' => $user->id,
        'decided_at' => now(),
    ]);

    $this->get(route('attendance.calendar.index', [
        'year' => 2026,
        'employee_id' => $inactiveEmployee->id,
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected_employee_id', $inactiveEmployee->id)
            ->where('selected_employee.id', $inactiveEmployee->id)
            ->where('selected_employee.name', 'Alice Tech')
            ->has('approved_leaves', 0)
            ->where('pending_request_count', 0)
            ->where('employees.0.id', $inactiveEmployee->id));
});

test('users without approve permission cannot view another employee via employee_id query', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceCalendarFixtures();
    ['employee' => $ownEmployee, 'leaveType' => $leaveType] = makeAttendanceCalendarActors($company);
    ['employee' => $otherEmployee] = makeAttendanceCalendarActors($company);

    $ownEmployee->update(['user_id' => $user->id]);
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['attendance.leave-requests.view']);

    foreach ([$ownEmployee, $otherEmployee] as $employee) {
        LeaveRequest::query()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-02',
            'total_days' => 2,
            'status' => 'approved',
            'approved_by' => $user->id,
            'decided_at' => now(),
        ]);
    }

    $this->get(route('attendance.calendar.index', [
        'year' => 2026,
        'employee_id' => $otherEmployee->id,
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected_employee_id', $ownEmployee->id)
            ->where('can_select_employee', false)
            ->has('approved_leaves', 1)
            ->where('approved_leaves.0.employee.id', $ownEmployee->id));
});

test('cross year approved leave appears in both years on calendar', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceCalendarFixtures();
    ['employee' => $employee, 'leaveType' => $leaveType] = makeAttendanceCalendarActors($company);
    $employee->update(['user_id' => $user->id]);
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['attendance.leave-requests.view']);

    LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2025-12-30',
        'end_date' => '2026-01-02',
        'total_days' => 4,
        'status' => 'approved',
        'approved_by' => $user->id,
        'decided_at' => now(),
    ]);

    $this->get(route('attendance.calendar.index', ['year' => 2025]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('approved_leaves', 1));

    $this->get(route('attendance.calendar.index', ['year' => 2026]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('approved_leaves', 1));
});

test('attendance calendar exposes create form props for users with create permission', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceCalendarFixtures();
    ['employee' => $employee, 'leaveType' => $leaveType] = makeAttendanceCalendarActors($company);
    ['employee' => $otherEmployee] = makeAttendanceCalendarActors($company);

    $employee->update(['user_id' => $user->id]);
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.create',
        'attendance.leave-requests.approve',
    ]);

    $response = $this->get(route('attendance.calendar.index', ['year' => 2026]));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.create', true)
            ->where('can.approve', true)
            ->where('selected_employee_id', $employee->id)
            ->has('form_leave_types', 2)
            ->has('form_employees', 2));

    $formEmployeeIds = collect($response->original->getData()['page']['props']['form_employees'])->pluck('id')->all();

    expect($formEmployeeIds)->toEqualCanonicalizing([$employee->id, $otherEmployee->id]);
});

test('attendance calendar hides create form props without create permission', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceCalendarFixtures();
    ['employee' => $employee] = makeAttendanceCalendarActors($company);

    $employee->update(['user_id' => $user->id]);
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['attendance.leave-requests.view']);

    $this->get(route('attendance.calendar.index', ['year' => 2026]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.create', false)
            ->where('can.approve', false)
            ->has('form_employees', 0));
});

test('attendance calendar form employees are limited to linked employee for non approvers', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceCalendarFixtures();
    ['employee' => $employee] = makeAttendanceCalendarActors($company);
    Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $employee->update(['user_id' => $user->id]);
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.create',
    ]);

    $this->get(route('attendance.calendar.index', ['year' => 2026]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.create', true)
            ->where('can.approve', false)
            ->has('form_employees', 1)
            ->where('form_employees.0.id', $employee->id));
});
