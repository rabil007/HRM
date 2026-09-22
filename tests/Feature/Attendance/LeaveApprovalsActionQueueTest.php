<?php

use App\Enums\LeaveApprovalApproverType;
use App\Enums\LeaveRequestApprovalStatus;
use App\Enums\SavedViewPage;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\Actions\SubmitLeaveRequestWithApprovals;
use App\Support\Attendance\LeaveBalanceManager;
use App\Support\SavedViews\SavedViewCatalog;
use Inertia\Testing\AssertableInertia as Assert;

function queueIds(User $user, Company $company, array $query = []): array
{
    $ids = [];

    test()->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('attendance.leave-approvals.index', $query))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$ids) {
            $page->component('attendance/leave-approvals')
                ->missing('filters.scope');
            $ids = collect($page->toArray()['props']['leave_requests'])->pluck('id')->all();
        });

    return $ids;
}

test('leave approvals queue shows only the current required pending step', function () {
    ['company' => $company, 'employee' => $employee, 'leaveType' => $leaveType] = authorizeLeaveReport();
    $current = makeActionableApprover($company, ['name' => 'Mohamed']);
    $waiting = makeActionableApprover($company, ['name' => 'Rima']);
    $fyi = makeActionableApprover($company, ['name' => 'FYI']);

    $actionable = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-02',
        'total_days' => 2,
        'status' => 'pending',
    ]);
    LeaveRequestApproval::factory()->approved()->create([
        'company_id' => $company->id,
        'leave_request_id' => $actionable->id,
        'sequence' => 1,
        'approver_employee_id' => $waiting['employee']->id,
        'approver_user_id' => $waiting['user']->id,
        'policy_step_label' => 'Earlier',
    ]);
    LeaveRequestApproval::factory()->create([
        'company_id' => $company->id,
        'leave_request_id' => $actionable->id,
        'sequence' => 2,
        'approver_employee_id' => $current['employee']->id,
        'approver_user_id' => $current['user']->id,
        'status' => LeaveRequestApprovalStatus::Pending,
        'is_required' => true,
        'policy_step_label' => 'Current',
    ]);
    LeaveRequestApproval::factory()->waiting()->create([
        'company_id' => $company->id,
        'leave_request_id' => $actionable->id,
        'sequence' => 3,
        'approver_employee_id' => $waiting['employee']->id,
        'approver_user_id' => $waiting['user']->id,
        'is_required' => true,
    ]);
    LeaveRequestApproval::factory()->create([
        'company_id' => $company->id,
        'leave_request_id' => $actionable->id,
        'sequence' => 4,
        'approver_employee_id' => $fyi['employee']->id,
        'approver_user_id' => $fyi['user']->id,
        'status' => LeaveRequestApprovalStatus::Skipped,
        'is_required' => false,
    ]);

    foreach (['approved', 'rejected', 'cancelled'] as $status) {
        $closed = createLeaveRequestRecord([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-02',
            'total_days' => 2,
            'status' => $status,
        ]);
        LeaveRequestApproval::factory()->create([
            'company_id' => $company->id,
            'leave_request_id' => $closed->id,
            'approver_employee_id' => $current['employee']->id,
            'approver_user_id' => $current['user']->id,
            'status' => LeaveRequestApprovalStatus::Pending,
        ]);
    }

    expect(queueIds($current['user'], $company))->toBe([$actionable->id])
        ->and(queueIds($current['user'], $company, ['scope' => 'all', 'status' => 'approved']))->toBe([$actionable->id])
        ->and(queueIds($current['user'], $company, ['scope' => 'assigned_to_me']))->toBe([$actionable->id])
        ->and(queueIds($waiting['user'], $company))->toBe([])
        ->and(queueIds($fyi['user'], $company))->toBe([]);

    grantCompanyPermissions($waiting['user'], $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.view_all',
        'attendance.leave-requests.approve',
    ]);

    expect(queueIds($waiting['user'], $company, ['scope' => 'all']))->toBe([]);
});

test('approving advances the action queue to the next required approver only', function () {
    ['company' => $company] = authorizeLeaveReport();
    $managed = makeManagedDepartment($company);
    $hr = makeActionableApprover($company, ['name' => 'Rima', 'work_email' => 'rima-queue@example.com']);
    $gm = makeActionableApprover($company, ['name' => 'Ahmed', 'work_email' => 'ahmed-queue@example.com']);
    configureCompanyLeaveApprovalSettings($company, $hr['employee']);
    ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
        ['type' => LeaveApprovalApproverType::HrApprover, 'required' => true],
        ['type' => LeaveApprovalApproverType::SpecificEmployee, 'employee_id' => $gm['employee']->id, 'required' => true],
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'department_id' => $managed['department']->id,
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
        ],
        notify: false,
    );

    expect(queueIds($managed['managerUser'], $company))->toBe([$leaveRequest->id])
        ->and(queueIds($hr['user'], $company))->toBe([])
        ->and(queueIds($gm['user'], $company))->toBe([]);

    test()->actingAs($managed['managerUser'])
        ->withSession(['current_company_id' => $company->id])
        ->put(route('attendance.leave-requests.approve', $leaveRequest))
        ->assertRedirect();

    expect(queueIds($managed['managerUser'], $company))->toBe([])
        ->and(queueIds($hr['user'], $company))->toBe([$leaveRequest->id])
        ->and(queueIds($gm['user'], $company))->toBe([]);

    test()->actingAs($hr['user'])
        ->withSession(['current_company_id' => $company->id])
        ->put(route('attendance.leave-requests.approve', $leaveRequest))
        ->assertRedirect();

    expect(queueIds($hr['user'], $company))->toBe([])
        ->and(queueIds($gm['user'], $company))->toBe([$leaveRequest->id]);

    test()->actingAs($gm['user'])
        ->withSession(['current_company_id' => $company->id])
        ->put(route('attendance.leave-requests.approve', $leaveRequest))
        ->assertRedirect();

    expect($leaveRequest->fresh()->status)->toBe('approved')
        ->and(queueIds($managed['managerUser'], $company))->toBe([])
        ->and(queueIds($hr['user'], $company))->toBe([])
        ->and(queueIds($gm['user'], $company))->toBe([]);
});

test('leave approvals stay inside the active company', function () {
    ['company' => $company, 'employee' => $employee, 'leaveType' => $leaveType] = authorizeLeaveReport();
    $approver = makeActionableApprover($company, ['name' => 'Local Approver']);

    $local = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-02',
        'total_days' => 2,
        'status' => 'pending',
    ]);
    LeaveRequestApproval::factory()->create([
        'company_id' => $company->id,
        'leave_request_id' => $local->id,
        'approver_employee_id' => $approver['employee']->id,
        'approver_user_id' => $approver['user']->id,
        'status' => LeaveRequestApprovalStatus::Pending,
    ]);

    $other = Company::query()->create([
        'name' => 'Other Approval Co',
        'slug' => 'other-approval-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $company->country_id,
        'currency_id' => $company->currency_id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
    $foreignEmployee = Employee::factory()->forCompany($other)->create(['status' => 'active']);
    $foreignType = LeaveType::factory()->for($other)->create(['status' => 'active']);
    $foreign = createLeaveRequestRecord([
        'company_id' => $other->id,
        'employee_id' => $foreignEmployee->id,
        'leave_type_id' => $foreignType->id,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-02',
        'total_days' => 2,
        'status' => 'pending',
    ]);
    LeaveRequestApproval::factory()->create([
        'company_id' => $other->id,
        'leave_request_id' => $foreign->id,
        'approver_employee_id' => $foreignEmployee->id,
        'approver_user_id' => $approver['user']->id,
        'status' => LeaveRequestApprovalStatus::Pending,
    ]);

    expect(queueIds($approver['user'], $company))->toBe([$local->id])
        ->and(LeaveRequest::query()->find($foreign->id))->not->toBeNull();
});

test('legacy leave approval saved views cannot broaden the action queue', function () {
    expect(SavedViewCatalog::forApply(SavedViewPage::LeaveApprovals, [
        'scope' => 'all',
        'status' => 'approved',
        'search' => 'Ahmed',
    ]))->toBe(['search' => 'Ahmed'])
        ->and(SavedViewCatalog::forApply(SavedViewPage::LeaveApprovals, [
            'scope' => 'assigned_to_me',
        ]))->toBe([]);
});
