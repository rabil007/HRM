<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\LeaveBalanceManager;
use App\Support\Attendance\LeaveTypeYearBalance;
use App\Support\Settings\CompanyTimezone;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{user: User, company: Company}
 */
function makeMyLeaveBalancesFixtures(): array
{
    $user = User::factory()->create();
    $country = Country::query()->create([
        'code' => 'ML'.fake()->unique()->numerify('##'),
        'name' => 'My Leave Balanceland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'ML'.fake()->unique()->numerify('##'),
        'name' => 'My Leave Balance Currency',
        'symbol' => 'M$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'My Leave Balance Co',
        'slug' => 'mlb-'.fake()->unique()->numerify('####'),
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

test('my leave exposes personal leave balances matching LeaveTypeYearBalance', function () {
    ['user' => $user, 'company' => $company] = makeMyLeaveBalancesFixtures();
    $year = (int) now(CompanyTimezone::forCompanyId((int) $company->id))->year;
    $employee = createAttendanceLeaveEmployee($company, ['user_id' => $user->id]);
    $leaveType = LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'name' => 'Annual Leave',
        'code' => 'AL',
        'days_per_year' => 30,
        'color' => '#0ea5e9',
    ]);

    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $employee->id, $year);

    LeaveBalance::query()
        ->where('company_id', $company->id)
        ->where('employee_id', $employee->id)
        ->where('leave_type_id', $leaveType->id)
        ->where('year', $year)
        ->update([
            'entitled_days' => 30,
            'carried_days' => 5,
            'used_days' => 10,
            'pending_days' => 2,
        ]);

    $expected = app(LeaveTypeYearBalance::class)->forEmployee(
        (int) $company->id,
        (int) $employee->id,
        $year,
    );
    $annual = collect($expected)->firstWhere('id', $leaveType->id);

    grantCompanyPermissions($user, $company, ['attendance.leave-requests.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get('/attendance/my-leave')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('attendance/my-leave')
            ->where('leave_balance_year', $year)
            ->has('leave_balances', count($expected))
            ->where('leave_balances', function ($balances) use ($annual) {
                $match = collect($balances)->firstWhere('id', $annual['id']);

                return $match !== null
                    && (float) $match['remaining_days'] === (float) $annual['remaining_days']
                    && (float) $match['used_days'] === (float) $annual['used_days']
                    && (float) $match['pending_days'] === (float) $annual['pending_days']
                    && (float) $match['total_available_days'] === (float) $annual['total_available_days']
                    && (float) $match['base_entitlement_days'] === (float) $annual['base_entitlement_days']
                    && (float) $match['carried_days'] === (float) $annual['carried_days'];
            })
            ->where('status_counts.pending', 0)
            ->where('status_counts.approved', 0)
            ->where('status_counts.rejected', 0)
            ->where('status_counts.cancelled', 0)
            ->has('status_counts.all'));
});

test('my leave status filter returns matching personal requests and empty status returns all', function () {
    ['user' => $user, 'company' => $company] = makeMyLeaveBalancesFixtures();
    $year = (int) now(CompanyTimezone::forCompanyId((int) $company->id))->year;
    $employee = createAttendanceLeaveEmployee($company, ['user_id' => $user->id]);
    $leaveType = LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'days_per_year' => 30,
    ]);

    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $employee->id, $year);

    $pending = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => "{$year}-06-10",
        'end_date' => "{$year}-06-11",
        'total_days' => 2,
        'status' => 'pending',
    ]);
    $approved = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => "{$year}-07-10",
        'end_date' => "{$year}-07-11",
        'total_days' => 2,
        'status' => 'approved',
    ]);
    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => "{$year}-08-10",
        'end_date' => "{$year}-08-11",
        'total_days' => 2,
        'status' => 'rejected',
    ]);
    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => "{$year}-09-10",
        'end_date' => "{$year}-09-11",
        'total_days' => 2,
        'status' => 'cancelled',
    ]);

    grantCompanyPermissions($user, $company, ['attendance.leave-requests.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get('/attendance/my-leave?status=pending')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('leave_requests', 1)
            ->where('leave_requests.0.id', $pending->id)
            ->where('filters.status', 'pending')
            ->where('status_counts.pending', 1)
            ->where('status_counts.approved', 1)
            ->where('status_counts.rejected', 1)
            ->where('status_counts.cancelled', 1));

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get('/attendance/my-leave')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('leave_requests', 4)
            ->where('filters.status', '')
            ->where('status_counts.all', 4));

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get('/attendance/my-leave?status=approved')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('leave_requests', 1)
            ->where('leave_requests.0.id', $approved->id));
});

test('my leave does not expose another employee leave balances', function () {
    ['user' => $user, 'company' => $company] = makeMyLeaveBalancesFixtures();
    $year = (int) now(CompanyTimezone::forCompanyId((int) $company->id))->year;
    $own = createAttendanceLeaveEmployee($company, ['user_id' => $user->id, 'name' => 'Own Employee']);
    $other = createAttendanceLeaveEmployee($company, ['name' => 'Other Employee']);
    $leaveType = LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'days_per_year' => 30,
    ]);

    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $own->id, $year);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $other->id, $year);

    LeaveBalance::query()
        ->where('employee_id', $other->id)
        ->where('year', $year)
        ->update([
            'used_days' => 20,
            'pending_days' => 7,
        ]);

    grantCompanyPermissions($user, $company, ['attendance.leave-requests.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get('/attendance/my-leave')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('leave_balances', function ($balances) {
                return collect($balances)->every(
                    fn ($balance) => (float) $balance['used_days'] !== 20.0
                        && (float) $balance['pending_days'] !== 7.0,
                );
            }));
});

test('my leave does not expose cross-company leave balances', function () {
    ['user' => $user, 'company' => $company] = makeMyLeaveBalancesFixtures();
    $year = (int) now(CompanyTimezone::forCompanyId((int) $company->id))->year;
    $own = createAttendanceLeaveEmployee($company, ['user_id' => $user->id]);

    $otherCompany = Company::query()->create([
        'name' => 'Other Leave Balance Co',
        'slug' => 'olb-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $company->country_id,
        'currency_id' => $company->currency_id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
    $foreignEmployee = createAttendanceLeaveEmployee($otherCompany);
    $foreignType = LeaveType::factory()->for($otherCompany)->create([
        'status' => 'active',
        'name' => 'Foreign Annual',
        'code' => 'FRN',
        'days_per_year' => 99,
    ]);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $otherCompany->id, (int) $foreignEmployee->id, $year);

    LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'days_per_year' => 30,
    ]);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $own->id, $year);

    grantCompanyPermissions($user, $company, ['attendance.leave-requests.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get('/attendance/my-leave')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('leave_balances', fn ($balances) => collect($balances)->every(
                fn ($balance) => $balance['code'] !== 'FRN' && $balance['name'] !== 'Foreign Annual',
            )));
});

test('excluded attendance leave departments do not provision my leave balances', function () {
    ['user' => $user, 'company' => $company] = makeMyLeaveBalancesFixtures();
    $year = (int) now(CompanyTimezone::forCompanyId((int) $company->id))->year;
    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Excluded Ops',
        'code' => 'EX'.fake()->unique()->numerify('##'),
        'status' => 'active',
        'include_in_attendance_leave' => false,
    ]);
    $employee = createAttendanceLeaveEmployee($company, [
        'user_id' => $user->id,
        'department_id' => $department->id,
    ]);
    LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'days_per_year' => 30,
    ]);

    $balancesBefore = LeaveBalance::query()->where('company_id', $company->id)->count();

    grantCompanyPermissions($user, $company, ['attendance.leave-requests.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get('/attendance/my-leave')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('linked_employee_attendance_leave_enabled', false)
            ->where('leave_balances', [])
            ->where('leave_balance_year', null));

    expect(LeaveBalance::query()->where('company_id', $company->id)->count())->toBe($balancesBefore)
        ->and(LeaveBalance::query()->where('employee_id', $employee->id)->where('year', $year)->count())->toBe(0);
});

test('leave approvals page does not expose personal leave balances', function () {
    ['user' => $user, 'company' => $company] = makeMyLeaveBalancesFixtures();
    $year = (int) now(CompanyTimezone::forCompanyId((int) $company->id))->year;
    $employee = createAttendanceLeaveEmployee($company, ['user_id' => $user->id]);
    LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'days_per_year' => 30,
    ]);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $employee->id, $year);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.approve',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get('/attendance/leave-approvals')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('attendance/leave-approvals')
            ->missing('leave_balances')
            ->missing('leave_balance_year'));
});
