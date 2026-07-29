<?php

use App\Enums\LeaveApprovalApproverType;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\LeaveApprovalPolicy;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\Actions\SubmitLeaveRequestWithApprovals;
use Illuminate\Support\Facades\DB;

/**
 * @return array{user: User, company: Company}
 */
function makePolicySnapshotDeletionFixtures(): array
{
    $user = User::factory()->create();
    $country = Country::query()->create([
        'code' => 'PS'.fake()->unique()->numerify('##'),
        'name' => 'Policy Snapshotland',
        'dial_code' => '+980',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'PS'.fake()->unique()->numerify('##'),
        'name' => 'Policy Snapshot Currency',
        'symbol' => 'P$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Policy Snapshot Co',
        'slug' => 'ps-'.fake()->unique()->numerify('####'),
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

test('policy referenced by leave_request_approvals policy_id cannot be deleted even after steps are recreated', function () {
    ['user' => $user, 'company' => $company] = makePolicySnapshotDeletionFixtures();
    $managed = makeManagedDepartment($company);
    $policy = ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'department_id' => $managed['department']->id,
    ]);
    $leaveType = LeaveType::factory()->for($company)->create(['status' => 'active', 'days_per_year' => 30]);

    $leaveRequest = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $company->id,
        attributes: [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-02',
            'total_days' => 2,
            'reason' => 'Trip',
        ],
        notify: false,
    );

    expect($leaveRequest->approvals->first()?->policy_id)->toBe($policy->id);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-approval-policies.update',
        'attendance.leave-approval-policies.delete',
    ]);

    $this->actingAs($user)
        ->put("/attendance/leave-approval-policies/{$policy->id}", [
            'name' => 'Renamed Policy',
            'description' => null,
            'is_default' => true,
            'status' => 'active',
            'steps' => [
                [
                    'approver_type' => LeaveApprovalApproverType::DepartmentManager->value,
                    'is_required' => true,
                ],
            ],
        ])
        ->assertRedirect();

    expect($leaveRequest->fresh()->approvals->first()?->policy_id)->toBe($policy->id);

    $nonDefaultCandidate = LeaveApprovalPolicy::factory()
        ->forCompany($company)
        ->withDepartmentManagerStep()
        ->create(['is_default' => false, 'name' => 'Alternate policy']);

    $this->put("/attendance/leave-approval-policies/{$nonDefaultCandidate->id}/default")
        ->assertRedirect();

    $this->delete("/attendance/leave-approval-policies/{$policy->id}")
        ->assertRedirect(route('attendance.leave-approval-policies.index'))
        ->assertSessionHasErrors('policy');

    expect($policy->fresh())->not->toBeNull();
});

test('unused non-default leave approval policy can be deleted', function () {
    ['user' => $user, 'company' => $company] = makePolicySnapshotDeletionFixtures();
    ensureDefaultLeaveApprovalPolicy($company);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-approval-policies.delete',
    ]);

    $unused = LeaveApprovalPolicy::factory()
        ->forCompany($company)
        ->withDepartmentManagerStep()
        ->create(['is_default' => false, 'name' => 'Unused policy']);

    $this->actingAs($user)
        ->delete("/attendance/leave-approval-policies/{$unused->id}")
        ->assertRedirect(route('attendance.leave-approval-policies.index'))
        ->assertSessionHas('success');

    expect(LeaveApprovalPolicy::query()->whereKey($unused->id)->exists())->toBeFalse();
});

test('cross-company approval snapshot does not block deletion in another company', function () {
    ['user' => $userA, 'company' => $companyA] = makePolicySnapshotDeletionFixtures();
    ['company' => $companyB] = makePolicySnapshotDeletionFixtures();

    $managedA = makeManagedDepartment($companyA);
    $managedB = makeManagedDepartment($companyB);

    $policyA = ensureDefaultLeaveApprovalPolicy($companyA);
    ensureDefaultLeaveApprovalPolicy($companyB);

    $policyB = LeaveApprovalPolicy::factory()
        ->forCompany($companyB)
        ->withDepartmentManagerStep()
        ->create(['is_default' => false, 'name' => 'Company B disposable']);

    $employeeA = Employee::factory()->forCompany($companyA)->create([
        'status' => 'active',
        'department_id' => $managedA['department']->id,
    ]);
    $employeeB = Employee::factory()->forCompany($companyB)->create([
        'status' => 'active',
        'department_id' => $managedB['department']->id,
    ]);
    $leaveTypeA = LeaveType::factory()->for($companyA)->create(['status' => 'active']);
    $leaveTypeB = LeaveType::factory()->for($companyB)->create(['status' => 'active']);

    app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $companyA->id,
        attributes: [
            'employee_id' => $employeeA->id,
            'leave_type_id' => $leaveTypeA->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-02',
            'total_days' => 2,
        ],
        notify: false,
    );

    app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $companyB->id,
        attributes: [
            'employee_id' => $employeeB->id,
            'leave_type_id' => $leaveTypeB->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-02',
            'total_days' => 2,
        ],
        notify: false,
    );

    grantCompanyPermissions($userA, $companyA, [
        'attendance.leave-approval-policies.delete',
    ]);

    $this->actingAs($userA)
        ->withSession(['current_company_id' => $companyA->id])
        ->delete("/attendance/leave-approval-policies/{$policyB->id}")
        ->assertNotFound();

    expect($policyA->fresh())->not->toBeNull()
        ->and($policyB->fresh())->not->toBeNull();

    $userB = User::factory()->create();
    DB::table('company_user')->insert([
        'company_id' => $companyB->id,
        'user_id' => $userB->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    grantCompanyPermissions($userB, $companyB, [
        'attendance.leave-approval-policies.delete',
    ]);

    $this->actingAs($userB)
        ->withSession(['current_company_id' => $companyB->id])
        ->delete("/attendance/leave-approval-policies/{$policyB->id}")
        ->assertRedirect(route('attendance.leave-approval-policies.index'))
        ->assertSessionHas('success');

    expect(LeaveApprovalPolicy::query()->whereKey($policyB->id)->exists())->toBeFalse()
        ->and($policyA->fresh())->not->toBeNull();
});
