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
use App\Support\Attendance\Actions\SubmitLeaveRequestWithApprovals;
use App\Support\Attendance\LeaveBalanceManager;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{user: User, company: Company}
 */
function makeMutationAuthFixtures(): array
{
    $user = User::factory()->create(['status' => 'active']);
    $country = Country::query()->create([
        'code' => 'MA'.fake()->unique()->numerify('##'),
        'name' => 'Mutation Authland',
        'dial_code' => '+991',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'MA'.fake()->unique()->numerify('##'),
        'name' => 'Mutation Currency',
        'symbol' => 'M$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Mutation Auth Co',
        'slug' => 'ma-'.fake()->unique()->numerify('####'),
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
 * @return array{leaveRequest: LeaveRequest, employee: Employee, leaveType: LeaveType, managerUser: User}
 */
function makePendingRequestWithAssignedApprover(Company $company, User $ownerUser): array
{
    $managed = makeManagedDepartment($company);
    ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'department_id' => $managed['department']->id,
        'user_id' => $ownerUser->id,
    ]);
    $leaveType = LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'days_per_year' => 30,
    ]);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $employee->id, 2026);

    $leaveRequest = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $company->id,
        existing: null,
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
    ];
}

test('assigned approver can view but cannot edit cancel or delete', function () {
    ['user' => $owner, 'company' => $company] = makeMutationAuthFixtures();
    $context = makePendingRequestWithAssignedApprover($company, $owner);
    $leaveRequest = $context['leaveRequest'];
    $managerUser = $context['managerUser'];

    grantCompanyPermissions($managerUser, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.update',
        'attendance.leave-requests.delete',
        'attendance.leave-requests.approve',
    ]);

    $this->actingAs($managerUser)
        ->withSession(['current_company_id' => $company->id])
        ->get("/attendance/leave-requests/{$leaveRequest->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('attendance/leave-request')
            ->where('leave_request.can_edit', false)
            ->where('leave_request.can_cancel', false)
            ->where('leave_request.can_delete', false)
            ->where('leave_request.can_approve_current_step', true)
        );

    $this->put("/attendance/leave-requests/{$leaveRequest->id}", [
        'employee_id' => $context['employee']->id,
        'leave_type_id' => $context['leaveType']->id,
        'start_date' => '2026-06-11',
        'end_date' => '2026-06-13',
        'reason' => 'Changed',
    ])->assertForbidden();

    $this->put("/attendance/leave-requests/{$leaveRequest->id}/cancel", [
        'cancellation_reason' => 'No longer needed',
    ])->assertForbidden();

    $this->delete("/attendance/leave-requests/{$leaveRequest->id}")
        ->assertForbidden();

    expect($leaveRequest->fresh())->not->toBeNull()
        ->and($leaveRequest->fresh()->status)->toBe('pending');
});

test('request owner can edit and cancel before approval begins', function () {
    ['user' => $owner, 'company' => $company] = makeMutationAuthFixtures();
    $context = makePendingRequestWithAssignedApprover($company, $owner);
    $leaveRequest = $context['leaveRequest'];

    grantCompanyPermissions($owner, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.update',
        'attendance.leave-requests.delete',
    ]);

    $this->actingAs($owner)
        ->withSession(['current_company_id' => $company->id])
        ->get("/attendance/leave-requests/{$leaveRequest->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('leave_request.can_edit', true)
            ->where('leave_request.can_cancel', true)
            ->where('leave_request.can_delete', true)
        );

    $this->put("/attendance/leave-requests/{$leaveRequest->id}", [
        'employee_id' => $context['employee']->id,
        'leave_type_id' => $context['leaveType']->id,
        'start_date' => '2026-06-11',
        'end_date' => '2026-06-13',
        'reason' => 'Updated by owner',
    ])->assertRedirect();

    expect($leaveRequest->fresh()->reason)->toBe('Updated by owner');

    $this->put("/attendance/leave-requests/{$leaveRequest->id}/cancel", [
        'cancellation_reason' => 'Plans changed',
    ])->assertRedirect();

    expect($leaveRequest->fresh()->status)->toBe('cancelled');
});

test('view_all admin with mutation permission can administer; view_all alone cannot mutate', function () {
    ['user' => $owner, 'company' => $company] = makeMutationAuthFixtures();
    $context = makePendingRequestWithAssignedApprover($company, $owner);
    $leaveRequest = $context['leaveRequest'];

    $admin = User::factory()->create(['status' => 'active']);
    DB::table('company_user')->insert([
        'company_id' => $company->id,
        'user_id' => $admin->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    grantCompanyPermissions($admin, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.view_all',
    ]);

    $this->actingAs($admin)
        ->withSession(['current_company_id' => $company->id])
        ->put("/attendance/leave-requests/{$leaveRequest->id}", [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-06-11',
            'end_date' => '2026-06-13',
            'reason' => 'Should fail',
        ])
        ->assertForbidden();

    grantCompanyPermissions($admin, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.view_all',
        'attendance.leave-requests.update',
        'attendance.leave-requests.delete',
    ]);

    $this->put("/attendance/leave-requests/{$leaveRequest->id}", [
        'employee_id' => $context['employee']->id,
        'leave_type_id' => $context['leaveType']->id,
        'start_date' => '2026-06-11',
        'end_date' => '2026-06-13',
        'reason' => 'Admin update',
    ])->assertRedirect();

    expect($leaveRequest->fresh()->reason)->toBe('Admin update');
});

test('cross-company leave request mutation returns 404', function () {
    ['user' => $owner, 'company' => $company] = makeMutationAuthFixtures();
    $context = makePendingRequestWithAssignedApprover($company, $owner);
    $leaveRequest = $context['leaveRequest'];

    ['user' => $otherUser, 'company' => $otherCompany] = makeMutationAuthFixtures();
    grantCompanyPermissions($otherUser, $otherCompany, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.view_all',
        'attendance.leave-requests.update',
        'attendance.leave-requests.delete',
    ]);

    $this->actingAs($otherUser)
        ->withSession(['current_company_id' => $otherCompany->id])
        ->get("/attendance/leave-requests/{$leaveRequest->id}")
        ->assertNotFound();

    $this->put("/attendance/leave-requests/{$leaveRequest->id}", [
        'employee_id' => $context['employee']->id,
        'leave_type_id' => $context['leaveType']->id,
        'start_date' => '2026-06-11',
        'end_date' => '2026-06-13',
        'reason' => 'Cross company',
    ])->assertNotFound();

    $this->delete("/attendance/leave-requests/{$leaveRequest->id}")
        ->assertNotFound();
});

test('assigned approver can approve current pending step', function () {
    ['user' => $owner, 'company' => $company] = makeMutationAuthFixtures();
    $context = makePendingRequestWithAssignedApprover($company, $owner);
    $leaveRequest = $context['leaveRequest'];
    $managerUser = $context['managerUser'];

    grantCompanyPermissions($managerUser, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.approve',
    ]);

    $this->actingAs($managerUser)
        ->withSession(['current_company_id' => $company->id])
        ->put("/attendance/leave-requests/{$leaveRequest->id}/approve")
        ->assertRedirect();

    expect($leaveRequest->fresh()->status)->toBe('approved')
        ->and(LeaveRequestApproval::query()
            ->where('leave_request_id', $leaveRequest->id)
            ->where('status', LeaveRequestApprovalStatus::Approved)
            ->exists())->toBeTrue();
});
