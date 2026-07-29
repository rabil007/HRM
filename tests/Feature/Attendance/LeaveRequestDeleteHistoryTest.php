<?php

use App\Enums\LeaveApprovalApproverType;
use App\Enums\LeaveRequestApprovalStatus;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\Actions\ApproveLeaveRequestStep;
use App\Support\Attendance\Actions\CancelLeaveRequestWorkflow;
use App\Support\Attendance\Actions\DeleteLeaveRequest;
use App\Support\Attendance\Actions\SubmitLeaveRequestWithApprovals;
use App\Support\Attendance\LeaveBalanceManager;
use Illuminate\Support\Facades\DB;

/**
 * @return array{user: User, company: Company}
 */
function makeDeleteHistoryFixtures(): array
{
    $user = User::factory()->create(['status' => 'active']);
    $country = Country::query()->create([
        'code' => 'DH'.fake()->unique()->numerify('##'),
        'name' => 'Delete Historyland',
        'dial_code' => '+988',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'DH'.fake()->unique()->numerify('##'),
        'name' => 'Delete History Currency',
        'symbol' => 'D$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Delete History Co',
        'slug' => 'dh-'.fake()->unique()->numerify('####'),
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
 * @return array{
 *     leaveRequest: LeaveRequest,
 *     employee: Employee,
 *     leaveType: LeaveType,
 *     managerUser: User,
 *     hrUser: User,
 * }
 */
function makeDeleteHistoryPendingRequest(Company $company, User $owner): array
{
    $managed = makeManagedDepartment($company);
    ['employee' => $hr, 'user' => $hrUser] = makeActionableApprover($company, [
        'name' => 'HR Approver',
        'work_email' => 'hr-delete@example.com',
    ]);
    configureCompanyLeaveApprovalSettings($company, $hr);

    ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
        ['type' => LeaveApprovalApproverType::HrApprover, 'required' => true],
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'department_id' => $managed['department']->id,
        'user_id' => $owner->id,
    ]);
    $leaveType = LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'days_per_year' => 30,
    ]);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $employee->id, 2026);

    $leaveRequest = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $company->id,
        attributes: [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-12',
            'total_days' => 3,
            'reason' => 'Trip',
        ],
        reserveBalance: true,
        notify: false,
    );

    return [
        'leaveRequest' => $leaveRequest,
        'employee' => $employee,
        'leaveType' => $leaveType,
        'managerUser' => $managed['managerUser'],
        'hrUser' => $hrUser,
    ];
}

test('unacted pending request may be deleted by authorised owner', function () {
    ['user' => $owner, 'company' => $company] = makeDeleteHistoryFixtures();
    $context = makeDeleteHistoryPendingRequest($company, $owner);

    grantCompanyPermissions($owner, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.delete',
    ]);

    $this->actingAs($owner)
        ->withSession(['current_company_id' => $company->id])
        ->delete("/attendance/leave-requests/{$context['leaveRequest']->id}")
        ->assertRedirect(route('attendance.leave-requests.index'))
        ->assertSessionHas('success');

    expect(LeaveRequest::query()->whereKey($context['leaveRequest']->id)->exists())->toBeFalse()
        ->and(LeaveRequestApproval::query()->where('leave_request_id', $context['leaveRequest']->id)->count())->toBe(0);
});

test('partially approved request cannot be deleted', function () {
    ['user' => $owner, 'company' => $company] = makeDeleteHistoryFixtures();
    $context = makeDeleteHistoryPendingRequest($company, $owner);
    $leaveRequest = $context['leaveRequest'];

    app(ApproveLeaveRequestStep::class)->handle(
        $leaveRequest,
        $context['managerUser'],
        (int) $company->id,
    );

    grantCompanyPermissions($owner, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.delete',
    ]);

    $this->actingAs($owner)
        ->withSession(['current_company_id' => $company->id])
        ->from('/attendance/leave-requests')
        ->delete("/attendance/leave-requests/{$leaveRequest->id}")
        ->assertRedirect(route('attendance.leave-requests.index'))
        ->assertSessionHasErrors([
            'leave_request' => 'This leave request cannot be deleted because the approval process has already started. Cancel it instead to preserve approval history.',
        ]);

    expect($leaveRequest->fresh())->not->toBeNull()
        ->and($leaveRequest->fresh()->status)->toBe('pending')
        ->and($leaveRequest->fresh()->approvals->firstWhere('sequence', 1)?->status)
        ->toBe(LeaveRequestApprovalStatus::Approved);
});

test('cancelled request with acted approval history remains preserved and cannot be deleted', function () {
    ['user' => $owner, 'company' => $company] = makeDeleteHistoryFixtures();
    $context = makeDeleteHistoryPendingRequest($company, $owner);
    $leaveRequest = $context['leaveRequest'];

    app(ApproveLeaveRequestStep::class)->handle(
        $leaveRequest,
        $context['managerUser'],
        (int) $company->id,
    );

    app(CancelLeaveRequestWorkflow::class)->handle(
        $leaveRequest->fresh(),
        $owner,
        (int) $company->id,
        'Plans changed',
    );

    expect($leaveRequest->fresh()->status)->toBe('cancelled');

    grantCompanyPermissions($owner, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.delete',
    ]);

    $this->actingAs($owner)
        ->withSession(['current_company_id' => $company->id])
        ->from('/attendance/leave-requests')
        ->delete("/attendance/leave-requests/{$leaveRequest->id}")
        ->assertRedirect(route('attendance.leave-requests.index'))
        ->assertSessionHasErrors('leave_request');

    expect($leaveRequest->fresh())->not->toBeNull()
        ->and(LeaveRequestApproval::query()
            ->where('leave_request_id', $leaveRequest->id)
            ->where('status', LeaveRequestApprovalStatus::Approved)
            ->exists())->toBeTrue();
});

test('cancellation preserves completed approvals', function () {
    ['user' => $owner, 'company' => $company] = makeDeleteHistoryFixtures();
    $context = makeDeleteHistoryPendingRequest($company, $owner);
    $leaveRequest = $context['leaveRequest'];

    app(ApproveLeaveRequestStep::class)->handle(
        $leaveRequest,
        $context['managerUser'],
        (int) $company->id,
        comments: 'Looks good',
    );

    $approvedStep = $leaveRequest->fresh()->approvals->firstWhere('sequence', 1);
    expect($approvedStep?->status)->toBe(LeaveRequestApprovalStatus::Approved);

    app(CancelLeaveRequestWorkflow::class)->handle(
        $leaveRequest->fresh(),
        $owner,
        (int) $company->id,
        'No longer needed',
    );

    $leaveRequest->refresh()->load('approvals');

    expect($leaveRequest->status)->toBe('cancelled')
        ->and($leaveRequest->approvals->firstWhere('sequence', 1)?->status)
        ->toBe(LeaveRequestApprovalStatus::Approved)
        ->and($leaveRequest->approvals->firstWhere('sequence', 1)?->comments)->toBe('Looks good')
        ->and($leaveRequest->approvals->firstWhere('sequence', 2)?->status)
        ->toBe(LeaveRequestApprovalStatus::Cancelled);
});

test('balance is released exactly once when deleting an unacted pending request', function () {
    ['user' => $owner, 'company' => $company] = makeDeleteHistoryFixtures();
    $context = makeDeleteHistoryPendingRequest($company, $owner);
    $leaveRequest = $context['leaveRequest'];

    $balanceBeforeDelete = LeaveBalance::query()
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2026)
        ->value('pending_days');

    expect((float) $balanceBeforeDelete)->toBe(3.0);

    app(DeleteLeaveRequest::class)->handle($leaveRequest, (int) $company->id);

    $balanceAfterDelete = LeaveBalance::query()
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2026)
        ->value('pending_days');

    expect((float) $balanceAfterDelete)->toBe(0.0);
});
