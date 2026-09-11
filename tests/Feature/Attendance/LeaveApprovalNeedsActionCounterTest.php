<?php

use App\Enums\LeaveApprovalApproverType;
use App\Enums\LeaveRequestApprovalStatus;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\LeaveApprovalNeedsActionCounter;
use App\Support\Attendance\LeaveBalanceManager;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/**
 * @return array{
 *     company: Company,
 *     leaveType: LeaveType,
 *     employee: Employee,
 *     manager: Employee,
 *     managerUser: User,
 *     otherUser: User
 * }
 */
function makeNeedsActionCounterFixtures(): array
{
    $country = Country::query()->create([
        'code' => 'NA'.fake()->unique()->numerify('##'),
        'name' => 'Needs Actionland',
        'dial_code' => '+977',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'NA'.fake()->unique()->numerify('##'),
        'name' => 'Needs Action Currency',
        'symbol' => 'N$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Needs Action Co',
        'slug' => 'na-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $managed = makeManagedDepartment($company);
    ensureDefaultLeaveApprovalPolicy($company);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'department_id' => $managed['department']->id,
    ]);
    $leaveType = LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'days_per_year' => 30,
    ]);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $employee->id, 2026);

    $otherUser = User::factory()->create(['status' => 'active']);
    DB::table('company_user')->insert([
        'company_id' => $company->id,
        'user_id' => $otherUser->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    grantCompanyPermissions($otherUser, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.approve',
    ]);

    return [
        'company' => $company,
        'leaveType' => $leaveType,
        'employee' => $employee,
        'manager' => $managed['manager'],
        'managerUser' => $managed['managerUser'],
        'otherUser' => $otherUser,
    ];
}

function createPendingLeaveWithApproval(
    Company $company,
    Employee $employee,
    LeaveType $leaveType,
    User $approverUser,
    Employee $approverEmployee,
    string $start,
    string $end,
    LeaveRequestApprovalStatus $approvalStatus = LeaveRequestApprovalStatus::Pending,
    string $requestStatus = 'pending',
): LeaveRequest {
    $leaveRequest = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => $start,
        'end_date' => $end,
        'total_days' => 2,
        'status' => $requestStatus,
    ]);

    LeaveRequestApproval::factory()->create([
        'company_id' => $company->id,
        'leave_request_id' => $leaveRequest->id,
        'sequence' => 1,
        'approver_type' => LeaveApprovalApproverType::DepartmentManager,
        'approver_employee_id' => $approverEmployee->id,
        'approver_user_id' => $approverUser->id,
        'status' => $approvalStatus,
        'is_required' => true,
    ]);

    return $leaveRequest;
}

test('pending approval assigned to current user counts', function () {
    $fixtures = makeNeedsActionCounterFixtures();

    createPendingLeaveWithApproval(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['leaveType'],
        $fixtures['managerUser'],
        $fixtures['manager'],
        '2026-06-10',
        '2026-06-11',
    );

    expect(app(LeaveApprovalNeedsActionCounter::class)->count(
        $fixtures['managerUser'],
        (int) $fixtures['company']->id,
    ))->toBe(1);
});

test('approved and rejected historical approval steps do not count', function () {
    $fixtures = makeNeedsActionCounterFixtures();

    createPendingLeaveWithApproval(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['leaveType'],
        $fixtures['managerUser'],
        $fixtures['manager'],
        '2026-06-10',
        '2026-06-11',
        LeaveRequestApprovalStatus::Approved,
        'approved',
    );

    createPendingLeaveWithApproval(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['leaveType'],
        $fixtures['managerUser'],
        $fixtures['manager'],
        '2026-07-10',
        '2026-07-11',
        LeaveRequestApprovalStatus::Rejected,
        'rejected',
    );

    expect(app(LeaveApprovalNeedsActionCounter::class)->count(
        $fixtures['managerUser'],
        (int) $fixtures['company']->id,
    ))->toBe(0);
});

test('another users pending approval does not count', function () {
    $fixtures = makeNeedsActionCounterFixtures();

    createPendingLeaveWithApproval(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['leaveType'],
        $fixtures['otherUser'],
        $fixtures['manager'],
        '2026-06-10',
        '2026-06-11',
    );

    expect(app(LeaveApprovalNeedsActionCounter::class)->count(
        $fixtures['managerUser'],
        (int) $fixtures['company']->id,
    ))->toBe(0);
});

test('another company pending approval does not count', function () {
    $fixtures = makeNeedsActionCounterFixtures();

    $otherCountry = Country::query()->create([
        'code' => 'XB'.fake()->unique()->numerify('##'),
        'name' => 'Other Badge Co Land',
        'dial_code' => '+976',
        'is_active' => true,
    ]);
    $otherCurrency = Currency::query()->create([
        'code' => 'XB'.fake()->unique()->numerify('##'),
        'name' => 'Other Badge Currency',
        'symbol' => 'X$',
        'is_active' => true,
    ]);
    $otherCompany = Company::query()->create([
        'name' => 'Other Badge Co',
        'slug' => 'xb-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $otherCountry->id,
        'currency_id' => $otherCurrency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    DB::table('company_user')->insert([
        'company_id' => $otherCompany->id,
        'user_id' => $fixtures['managerUser']->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    grantCompanyPermissions($fixtures['managerUser'], $otherCompany, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.approve',
    ]);

    $otherManaged = makeManagedDepartment($otherCompany);
    $otherEmployee = Employee::factory()->forCompany($otherCompany)->create([
        'status' => 'active',
        'department_id' => $otherManaged['department']->id,
    ]);
    $otherLeaveType = LeaveType::factory()->for($otherCompany)->create([
        'status' => 'active',
        'days_per_year' => 30,
    ]);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $otherCompany->id, (int) $otherEmployee->id, 2026);

    createPendingLeaveWithApproval(
        $otherCompany,
        $otherEmployee,
        $otherLeaveType,
        $fixtures['managerUser'],
        $otherManaged['manager'],
        '2026-06-10',
        '2026-06-11',
    );

    createPendingLeaveWithApproval(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['leaveType'],
        $fixtures['managerUser'],
        $fixtures['manager'],
        '2026-07-10',
        '2026-07-11',
    );

    $counter = app(LeaveApprovalNeedsActionCounter::class);

    expect($counter->count($fixtures['managerUser'], (int) $fixtures['company']->id))->toBe(1)
        ->and($counter->count($fixtures['managerUser'], (int) $otherCompany->id))->toBe(1);
});

test('multiple actionable leave requests return the correct count', function () {
    $fixtures = makeNeedsActionCounterFixtures();

    createPendingLeaveWithApproval(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['leaveType'],
        $fixtures['managerUser'],
        $fixtures['manager'],
        '2026-06-10',
        '2026-06-11',
    );
    createPendingLeaveWithApproval(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['leaveType'],
        $fixtures['managerUser'],
        $fixtures['manager'],
        '2026-07-10',
        '2026-07-11',
    );
    createPendingLeaveWithApproval(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['leaveType'],
        $fixtures['managerUser'],
        $fixtures['manager'],
        '2026-08-10',
        '2026-08-11',
        LeaveRequestApprovalStatus::Approved,
        'approved',
    );

    expect(app(LeaveApprovalNeedsActionCounter::class)->count(
        $fixtures['managerUser'],
        (int) $fixtures['company']->id,
    ))->toBe(2);
});

test('zero actionable requests returns zero', function () {
    $fixtures = makeNeedsActionCounterFixtures();

    expect(app(LeaveApprovalNeedsActionCounter::class)->count(
        $fixtures['managerUser'],
        (int) $fixtures['company']->id,
    ))->toBe(0);
});

test('unauthorized users do not receive a meaningful leave approvals count', function () {
    $fixtures = makeNeedsActionCounterFixtures();

    createPendingLeaveWithApproval(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['leaveType'],
        $fixtures['managerUser'],
        $fixtures['manager'],
        '2026-06-10',
        '2026-06-11',
    );

    $viewerOnly = User::factory()->create(['status' => 'active']);
    DB::table('company_user')->insert([
        'company_id' => $fixtures['company']->id,
        'user_id' => $viewerOnly->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    grantCompanyPermissions($viewerOnly, $fixtures['company'], [
        'attendance.leave-requests.view',
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($fixtures['company']->id);

    expect(app(LeaveApprovalNeedsActionCounter::class)->count(
        $viewerOnly,
        (int) $fixtures['company']->id,
    ))->toBe(0);

    $this->actingAs($viewerOnly)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('attendance.my-leave.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.leave_approvals_count', 0));
});

test('leave approvals count is shared in inertia auth props for authorized approvers', function () {
    $fixtures = makeNeedsActionCounterFixtures();

    createPendingLeaveWithApproval(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['leaveType'],
        $fixtures['managerUser'],
        $fixtures['manager'],
        '2026-06-10',
        '2026-06-11',
    );

    $this->actingAs($fixtures['managerUser'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('attendance.leave-approvals.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.leave_approvals_count', 1)
            ->has('auth.my_tasks_count'));
});

test('waiting future step for the same user does not count until it becomes pending', function () {
    $fixtures = makeNeedsActionCounterFixtures();
    ['employee' => $hrEmployee, 'user' => $hrUser] = makeActionableApprover($fixtures['company'], [
        'name' => 'HR Approver',
        'work_email' => 'hr-needs-action@example.com',
    ]);

    $leaveRequest = createLeaveRequestRecord([
        'company_id' => $fixtures['company']->id,
        'employee_id' => $fixtures['employee']->id,
        'leave_type_id' => $fixtures['leaveType']->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-11',
        'total_days' => 2,
        'status' => 'pending',
    ]);

    LeaveRequestApproval::factory()->approved()->create([
        'company_id' => $fixtures['company']->id,
        'leave_request_id' => $leaveRequest->id,
        'sequence' => 1,
        'approver_type' => LeaveApprovalApproverType::HrApprover,
        'approver_employee_id' => $hrEmployee->id,
        'approver_user_id' => $hrUser->id,
        'is_required' => true,
    ]);

    LeaveRequestApproval::factory()->waiting()->create([
        'company_id' => $fixtures['company']->id,
        'leave_request_id' => $leaveRequest->id,
        'sequence' => 2,
        'approver_type' => LeaveApprovalApproverType::DepartmentManager,
        'approver_employee_id' => $fixtures['manager']->id,
        'approver_user_id' => $fixtures['managerUser']->id,
        'is_required' => true,
    ]);

    expect(app(LeaveApprovalNeedsActionCounter::class)->count(
        $fixtures['managerUser'],
        (int) $fixtures['company']->id,
    ))->toBe(0);

    LeaveRequestApproval::query()
        ->where('leave_request_id', $leaveRequest->id)
        ->where('sequence', 2)
        ->update(['status' => LeaveRequestApprovalStatus::Pending]);

    expect(app(LeaveApprovalNeedsActionCounter::class)->count(
        $fixtures['managerUser'],
        (int) $fixtures['company']->id,
    ))->toBe(1);
});
