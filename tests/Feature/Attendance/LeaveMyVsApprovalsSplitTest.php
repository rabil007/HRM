<?php

use App\Enums\LeaveRequestApprovalStatus;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\LeaveRequestApproval;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\LeaveBalanceManager;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{user: User, company: Company}
 */
function makeLeaveSplitFixtures(): array
{
    $user = User::factory()->create();
    $country = Country::query()->create([
        'code' => 'LS'.fake()->unique()->numerify('##'),
        'name' => 'Leave Splitland',
        'dial_code' => '+988',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'LS'.fake()->unique()->numerify('##'),
        'name' => 'Leave Split Currency',
        'symbol' => 'S$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Leave Split Co',
        'slug' => 'ls-'.fake()->unique()->numerify('####'),
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
function makeLeaveSplitActors(Company $company, int $year = 2026): array
{
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $leaveType = LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'days_per_year' => 30,
    ]);

    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $employee->id, $year);

    return ['employee' => $employee, 'leaveType' => $leaveType];
}

test('legacy leave-requests index redirects to my leave preserving query', function () {
    ['user' => $user, 'company' => $company] = makeLeaveSplitFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['attendance.leave-requests.view']);

    $this->get('/attendance/leave-requests?status=pending')
        ->assertRedirect(route('attendance.my-leave.index', ['status' => 'pending']));
});

test('my leave defaults to own requests with mine list mode', function () {
    ['user' => $user, 'company' => $company] = makeLeaveSplitFixtures();
    ['employee' => $ownEmployee, 'leaveType' => $leaveType] = makeLeaveSplitActors($company);
    ['employee' => $otherEmployee] = makeLeaveSplitActors($company);
    $ownEmployee->update(['user_id' => $user->id]);

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $ownEmployee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'total_days' => 3,
        'status' => 'pending',
    ]);

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $otherEmployee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-02',
        'total_days' => 2,
        'status' => 'pending',
    ]);

    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['attendance.leave-requests.view']);

    $this->get('/attendance/my-leave')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('attendance/my-leave')
            ->where('list_mode', 'mine')
            ->where('filters.scope', 'my')
            ->has('leave_requests', 1)
            ->where('leave_requests.0.employee.id', $ownEmployee->id));
});

test('leave approvals requires approve permission and defaults to needs action scope', function () {
    ['user' => $user, 'company' => $company] = makeLeaveSplitFixtures();
    ['employee' => $employee, 'leaveType' => $leaveType] = makeLeaveSplitActors($company);
    $managed = prepareLeaveRequestApprovalContext($company, $employee);

    $awaiting = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'total_days' => 3,
        'status' => 'pending',
    ]);

    LeaveRequestApproval::factory()->create([
        'company_id' => $company->id,
        'leave_request_id' => $awaiting->id,
        'sequence' => 1,
        'approver_employee_id' => $managed['manager']->id,
        'approver_user_id' => $managed['managerUser']->id,
        'status' => LeaveRequestApprovalStatus::Pending,
    ]);

    grantCompanyPermissions($user, $company, ['attendance.leave-requests.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get('/attendance/leave-approvals')
        ->assertForbidden();

    $this->actingAs($managed['managerUser'])
        ->withSession(['current_company_id' => $company->id])
        ->get('/attendance/leave-approvals')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('attendance/leave-approvals')
            ->where('list_mode', 'approvals')
            ->where('filters.scope', 'awaiting_my_approval')
            ->has('leave_requests', 1)
            ->where('leave_requests.0.id', $awaiting->id));
});

test('dashboard leave attention item links to leave approvals', function () {
    ['user' => $user, 'company' => $company] = makeLeaveSplitFixtures();
    ['employee' => $employee, 'leaveType' => $leaveType] = makeLeaveSplitActors($company);
    $managed = prepareLeaveRequestApprovalContext($company, $employee);

    $awaiting = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'total_days' => 3,
        'status' => 'pending',
    ]);

    LeaveRequestApproval::factory()->create([
        'company_id' => $company->id,
        'leave_request_id' => $awaiting->id,
        'sequence' => 1,
        'approver_employee_id' => $managed['manager']->id,
        'approver_user_id' => $managed['managerUser']->id,
        'status' => LeaveRequestApprovalStatus::Pending,
    ]);

    $this->actingAs($managed['managerUser'])
        ->withSession(['current_company_id' => $company->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('attention_items')
            ->where('attention_items', function ($items) {
                $leaveItem = collect($items)->firstWhere('key', 'leave_approvals');

                return $leaveItem !== null
                    && ($leaveItem['href'] ?? null) === route('attendance.leave-approvals.index');
            }));
});
