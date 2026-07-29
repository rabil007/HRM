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
use App\Support\Attendance\LeaveBalanceManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * @return array{user: User, company: Company}
 */
function makeLeaveEditFixtures(): array
{
    $user = User::factory()->create();
    $country = Country::query()->create([
        'code' => 'LE'.fake()->unique()->numerify('##'),
        'name' => 'Leave Editland',
        'dial_code' => '+990',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'LE'.fake()->unique()->numerify('##'),
        'name' => 'Leave Edit Currency',
        'symbol' => 'L$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Leave Edit Co',
        'slug' => 'le-'.fake()->unique()->numerify('####'),
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
 * @return array{employee: Employee, leaveType: LeaveType, manager: Employee, managerUser: User, hr: Employee, hrUser: User}
 */
function makeLeaveEditActors(Company $company): array
{
    $managed = makeManagedDepartment($company);
    ['employee' => $hr, 'user' => $hrUser] = makeActionableApprover($company, [
        'name' => 'HR Approver',
        'work_email' => 'hr-edit@example.com',
    ]);
    configureCompanyLeaveApprovalSettings($company, $hr, $managed['manager']);

    ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
        ['type' => LeaveApprovalApproverType::HrApprover, 'required' => true],
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

    return [
        'employee' => $employee,
        'leaveType' => $leaveType,
        'manager' => $managed['manager'],
        'managerUser' => $managed['managerUser'],
        'hr' => $hr,
        'hrUser' => $hrUser,
    ];
}

test('pending leave request can be edited before any approval step acts', function () {
    ['user' => $user, 'company' => $company] = makeLeaveEditFixtures();
    $actors = makeLeaveEditActors($company);
    $employee = $actors['employee'];
    $leaveType = $actors['leaveType'];
    $employee->update(['user_id' => $user->id]);
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.create',
        'attendance.leave-requests.update',
        'attendance.leave-requests.view',
    ]);

    Mail::fake();

    $this->post('/attendance/leave-requests', [
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-02',
        'reason' => 'Short trip',
    ])->assertRedirect(route('attendance.leave-requests.index'));

    $leaveRequest = LeaveRequest::query()->where('employee_id', $employee->id)->firstOrFail();
    expect($leaveRequest->approvals)->toHaveCount(2);

    $this->put("/attendance/leave-requests/{$leaveRequest->id}", [
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'reason' => 'Updated trip',
    ])->assertRedirect(route('attendance.leave-requests.index'))
        ->assertSessionHas('success');

    $leaveRequest->refresh();
    expect($leaveRequest->start_date->toDateString())->toBe('2026-06-10')
        ->and($leaveRequest->end_date->toDateString())->toBe('2026-06-12')
        ->and((float) $leaveRequest->total_days)->toBe(3.0)
        ->and($leaveRequest->reason)->toBe('Updated trip')
        ->and($leaveRequest->approvals)->toHaveCount(2)
        ->and($leaveRequest->approvals->every(fn ($a) => $a->status->isOpen()))->toBeTrue();

    $balance = LeaveBalance::query()
        ->where('employee_id', $employee->id)
        ->where('leave_type_id', $leaveType->id)
        ->where('year', 2026)
        ->firstOrFail();

    expect((float) $balance->pending_days)->toBe(3.0);
});

test('editing before action rebuilds the unacted approval chain', function () {
    ['user' => $user, 'company' => $company] = makeLeaveEditFixtures();
    $actors = makeLeaveEditActors($company);
    $employee = $actors['employee'];
    $leaveType = $actors['leaveType'];
    $employee->update(['user_id' => $user->id]);
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.create',
        'attendance.leave-requests.update',
    ]);

    $this->post('/attendance/leave-requests', [
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-02',
        'reason' => 'Trip',
    ])->assertRedirect();

    $leaveRequest = LeaveRequest::query()->where('employee_id', $employee->id)->firstOrFail();
    $originalApprovalIds = $leaveRequest->approvals->pluck('id')->all();
    $originalPendingUserId = $leaveRequest->approvals
        ->firstWhere('status', LeaveRequestApprovalStatus::Pending)
        ?->approver_user_id;

    // Move employee to a new managed department so rebuild resolves a different manager.
    $newManaged = makeManagedDepartment($company);
    $employee->update(['department_id' => $newManaged['department']->id]);

    $this->put("/attendance/leave-requests/{$leaveRequest->id}", [
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-03',
        'reason' => 'Rebuilt',
    ])->assertRedirect();

    $leaveRequest->refresh()->load('approvals');
    $newApprovalIds = $leaveRequest->approvals->pluck('id')->all();

    expect($newApprovalIds)->not->toEqual($originalApprovalIds)
        ->and($leaveRequest->approvals)->toHaveCount(2)
        ->and($leaveRequest->approvals->firstWhere('status', LeaveRequestApprovalStatus::Pending)?->approver_user_id)
        ->toBe($newManaged['managerUser']->id)
        ->and($leaveRequest->approvals->firstWhere('status', LeaveRequestApprovalStatus::Pending)?->approver_user_id)
        ->not->toBe($originalPendingUserId);

    expect(LeaveRequestApproval::query()->whereIn('id', $originalApprovalIds)->count())->toBe(0);
});

test('editing after an intermediate approval is rejected', function () {
    ['user' => $user, 'company' => $company] = makeLeaveEditFixtures();
    $actors = makeLeaveEditActors($company);
    $employee = $actors['employee'];
    $leaveType = $actors['leaveType'];
    $employee->update(['user_id' => $user->id]);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.create',
        'attendance.leave-requests.update',
    ]);

    $this->actingAs($user)
        ->post('/attendance/leave-requests', [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-02',
            'reason' => 'Trip',
        ])
        ->assertRedirect();

    $leaveRequest = LeaveRequest::query()->where('employee_id', $employee->id)->firstOrFail();

    $this->actingAs($actors['managerUser'])
        ->withSession(['current_company_id' => $company->id])
        ->put("/attendance/leave-requests/{$leaveRequest->id}/approve", [
            'comments' => 'OK',
        ])
        ->assertRedirect();

    expect($leaveRequest->fresh()->status)->toBe('pending')
        ->and($leaveRequest->fresh()->approvals->firstWhere('sequence', 1)->status)
        ->toBe(LeaveRequestApprovalStatus::Approved);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->from('/attendance/leave-requests')
        ->put("/attendance/leave-requests/{$leaveRequest->id}", [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-20',
            'reason' => 'Should fail',
        ])
        ->assertRedirect(route('attendance.leave-requests.index'))
        ->assertSessionHasErrors([
            'leave_request' => 'This leave request can no longer be edited because the approval process has already started.',
        ]);

    $leaveRequest->refresh();
    expect($leaveRequest->start_date->toDateString())->toBe('2026-06-01')
        ->and($leaveRequest->approvals->firstWhere('sequence', 1)->status)
        ->toBe(LeaveRequestApprovalStatus::Approved);
});

test('edit cannot race successfully after approval starts', function () {
    ['user' => $user, 'company' => $company] = makeLeaveEditFixtures();
    $actors = makeLeaveEditActors($company);
    $employee = $actors['employee'];
    $leaveType = $actors['leaveType'];
    $employee->update(['user_id' => $user->id]);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.create',
        'attendance.leave-requests.update',
    ]);

    $this->actingAs($user)
        ->post('/attendance/leave-requests', [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-02',
            'reason' => 'Trip',
        ])
        ->assertRedirect();

    $leaveRequest = LeaveRequest::query()->where('employee_id', $employee->id)->firstOrFail();

    // Simulate concurrent approval before the edit transaction rechecks approvals.
    LeaveRequestApproval::query()
        ->where('leave_request_id', $leaveRequest->id)
        ->where('sequence', 1)
        ->update([
            'status' => LeaveRequestApprovalStatus::Approved->value,
            'acted_at' => now(),
        ]);

    LeaveRequestApproval::query()
        ->where('leave_request_id', $leaveRequest->id)
        ->where('sequence', 2)
        ->update(['status' => LeaveRequestApprovalStatus::Pending->value]);

    $this->actingAs($user)
        ->from('/attendance/leave-requests')
        ->put("/attendance/leave-requests/{$leaveRequest->id}", [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-10',
            'reason' => 'Race',
        ])
        ->assertSessionHasErrors('leave_request');

    expect($leaveRequest->fresh()->start_date->toDateString())->toBe('2026-06-01')
        ->and(LeaveRequestApproval::query()->where('leave_request_id', $leaveRequest->id)->where('status', LeaveRequestApprovalStatus::Approved)->count())
        ->toBe(1);
});

test('cross-company leave request editing remains inaccessible', function () {
    ['user' => $user, 'company' => $company] = makeLeaveEditFixtures();
    $actors = makeLeaveEditActors($company);

    $otherCountry = Country::query()->create([
        'code' => 'LX'.fake()->unique()->numerify('##'),
        'name' => 'Otherland',
        'dial_code' => '+991',
        'is_active' => true,
    ]);
    $otherCurrency = Currency::query()->create([
        'code' => 'LX'.fake()->unique()->numerify('##'),
        'name' => 'Other Currency',
        'symbol' => 'X$',
        'is_active' => true,
    ]);
    $otherCompany = Company::query()->create([
        'name' => 'Other Co',
        'slug' => 'lx-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $otherCountry->id,
        'currency_id' => $otherCurrency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
    $otherActors = makeLeaveEditActors($otherCompany);

    $foreignRequest = createLeaveRequestRecord([
        'company_id' => $otherCompany->id,
        'employee_id' => $otherActors['employee']->id,
        'leave_type_id' => $otherActors['leaveType']->id,
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-02',
        'total_days' => 2,
        'status' => 'pending',
    ]);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.update',
        'attendance.leave-requests.view_all',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->put("/attendance/leave-requests/{$foreignRequest->id}", [
            'employee_id' => $actors['employee']->id,
            'leave_type_id' => $actors['leaveType']->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-12',
            'reason' => 'Cross company',
        ])
        ->assertNotFound();
});
